<?php

namespace ProcessWire;

/**
 * FwChangelog
 *
 * Per-page changelog: records which fields changed on every save (before → after),
 * who changed them and when, and shows that history in the Settings tab of the
 * page editor.
 *
 * @property int $retentionMonths Months to keep entries (0 = keep forever)
 * @property int $maxValueLength Max characters stored per value (0 = no limit)
 * @property int $mergeMinutes Window for merging consecutive edits of the same field by the same user (0 = never merge)
 * @property array $ignoredTemplates Template names that are never logged
 * @property array $ignoredFields Field names that are never logged
 */
class FwChangelog extends WireData implements Module, ConfigurableModule
{

    /**
     * Name of the database table holding the changelog rows
     */
    const TABLE_NAME = 'fw_changelog';

    /**
     * Permission required (besides page-edit access) to see the changelog
     */
    const PERMISSION_VIEW = 'fw-changelog-view';

    /**
     * Default configuration values
     *
     * @return array
     */
    public static function getDefaults()
    {
        return array(
            'retentionMonths' => 12,
            'maxValueLength' => 20000,
            'mergeMinutes' => 10,
            'ignoredTemplates' => array('admin', 'user', 'role', 'permission', 'language'),
            'ignoredFields' => array(),
        );
    }

    /**
     * Populate defaults so every setting exists before the saved config is applied
     */
    public function __construct()
    {
        parent::__construct();
        foreach (self::getDefaults() as $key => $value) {
            $this->set($key, $value);
        }
    }

    /**
     * Fieldtypes that are not compared as a field value on the page that holds them,
     * because their items are pages and are logged when those item pages are saved.
     */
    const DEFERRED_FIELDTYPES = array(
        // Also covers RepeaterMatrix and FieldsetPage: see resolveTarget()
        'FieldtypeRepeater',
        // PageTable items are regular pages with their own changelog
        'FieldtypePageTable',
    );

    /**
     * Separator used in the "field" column for sub-items of a field,
     * e.g. "images › photo.jpg" or "address › city". The first segment is always the field name.
     */
    const PATH_SEPARATOR = ' › ';

    /**
     * Rows computed before a save, waiting for the save to succeed.
     * Keyed by page id (full saves) or "pageId:fieldName" (single-field saves).
     *
     * @var array
     */
    protected $pending = array();

    /**
     * Ids of pages being deleted in this request (id => true)
     *
     * @var array
     */
    protected $deleting = array();

    /**
     * Identifier shared by every row written in this request, so the changes made
     * by one save (page plus its repeater items) can be shown as one group
     *
     * @var string
     */
    protected $saveId = '';

    /**
     * Attach the capture hooks
     */
    public function init()
    {
        $pages = $this->wire('pages');
        // Late priority, so values adjusted by other saveReady hooks are the ones compared
        $pages->addHookAfter('saveReady', $this, 'hookSaveReady', array('priority' => 200));
        $pages->addHookAfter('saved', $this, 'hookSaved');
        $pages->addHookAfter('saveFieldReady', $this, 'hookSaveFieldReady', array('priority' => 200));
        $pages->addHookAfter('savedField', $this, 'hookSavedField');
        $pages->addHookAfter('added', $this, 'hookPageEvent');
        $pages->addHookAfter('trashed', $this, 'hookPageEvent');
        $pages->addHookAfter('restored', $this, 'hookPageEvent');
        $pages->addHookAfter('deleteReady', $this, 'hookDeleteReady');

        // Retention cleanup: only runs when the LazyCron module is installed
        if ((int) $this->retentionMonths > 0) {
            $this->addHook('LazyCron::everyDay', $this, 'cleanup');
        }
    }

    /**
     * Number of saves shown per request in the page editor
     */
    const SAVES_PER_PAGE = 20;

    /**
     * In the page editor: add the changelog block to the Settings tab and answer
     * its AJAX requests
     */
    public function ready()
    {
        $page = $this->wire('page');
        if (!$page || $page->template != 'admin' || $page->process != 'ProcessPageEdit') return;
        if (!$this->userCanView()) return;

        if ($this->wire('config')->ajax && $this->wire('input')->get('fw_changelog')) {
            $this->addHookBefore('ProcessPageEdit::execute', $this, 'hookAjaxList');
            return;
        }

        $this->addHookAfter('ProcessPageEdit::buildFormSettings', $this, 'hookBuildFormSettings');
    }

    /**
     * Whether the current user may see changelogs
     *
     * @return bool
     */
    protected function userCanView()
    {
        $user = $this->wire('user');
        return $user->isSuperuser() || $user->hasPermission(self::PERMISSION_VIEW);
    }

    /**
     * Append the changelog block to the Settings tab: the number of logged
     * changes and a link that opens the history in a modal. FwChangelog.js builds
     * the modal and loads its content the first time it is opened.
     *
     * @param HookEvent $event
     */
    public function hookBuildFormSettings(HookEvent $event)
    {
        $editedPage = $event->object->getPage();
        if (!$editedPage || !$editedPage->id) return;
        $config = $this->wire('config');

        // Cache-busted by file modification time, so an updated file is never served stale
        foreach (array('css' => $config->styles, 'js' => $config->scripts) as $extension => $assets) {
            $file = "FwChangelog.$extension";
            $assets->add($config->urls->get('FwChangelog') . $file . '?v=' . (int) @filemtime(__DIR__ . '/' . $file));
        }

        $url = $config->urls->admin . 'page/edit/?id=' . (int) $editedPage->id . '&fw_changelog=1';

        // Texts used by FwChangelog.js, passed from here so they can be translated
        $config->js('FwChangelog', array(
            'loading' => $this->_('Loading…'),
            'error' => $this->_('Could not load the history.'),
            'close' => $this->_('Close'),
        ));

        /** @var InputfieldMarkup $f */
        $f = $this->wire('modules')->get('InputfieldMarkup');
        $f->attr('id+name', 'fw_changelog');
        $f->label = $this->_('Changelog');
        $f->icon = 'code-fork';
        $total = 0;
        $since = '';
        try {
            $query = $this->wire('database')->prepare(
                'SELECT COUNT(*), MIN(created) FROM `' . self::TABLE_NAME . '` WHERE pages_id=:pages_id'
            );
            $query->bindValue(':pages_id', (int) $editedPage->id, \PDO::PARAM_INT);
            $query->execute();
            list($total, $since) = $query->fetch(\PDO::FETCH_NUM);
            $query->closeCursor();
        } catch (\Exception $e) {
            $this->wire('log')->save('fw-changelog', "Count for page $editedPage->id: " . $e->getMessage());
        }
        $total = (int) $total;

        $sanitizer = $this->wire('sanitizer');
        if ($total > 0) {
            $f->value =
                $sanitizer->entities(sprintf(
                    $this->_n('%1$d edit since %2$s', '%1$d edits since %2$s', $total),
                    $total,
                    date('d/m/Y H:i:s', strtotime($since))
                )) . ' - ' .
                '<a href="#" class="fw-changelog-open"' .
                ' data-url="' . $sanitizer->entities($url) . '"' .
                ' data-title="' . $sanitizer->entities((string) $editedPage->get('title|name')) . '">' .
                $sanitizer->entities($this->_('View History')) . '</a>';
        } else {
            $f->value = $sanitizer->entities($this->_('No changes logged yet.'));
        }

        $event->return->append($f);
    }

    /**
     * AJAX: return one page of the changelog of the edited page as an HTML fragment
     *
     * ProcessPageEdit has already checked that the user may edit the page.
     *
     * @param HookEvent $event
     */
    public function hookAjaxList(HookEvent $event)
    {
        $editedPage = $event->object->getPage();
        $event->replace = true;
        if (!$editedPage || !$editedPage->id) {
            $event->return = '';
            return;
        }
        $offset = max(0, (int) $this->wire('input')->get('offset'));
        $event->return = $this->renderList($editedPage, $offset);
    }

    /**
     * Load the rows of one page of saves, newest first
     *
     * @param Page $page
     * @param int $offset Number of saves to skip
     * @param bool $hasMore Set to true when older saves exist beyond this page
     * @return array save_id => rows
     */
    protected function findSaves(Page $page, $offset, &$hasMore)
    {
        $database = $this->wire('database');
        $table = self::TABLE_NAME;
        $limit = self::SAVES_PER_PAGE;
        $hasMore = false;

        $query = $database->prepare(
            "SELECT save_id, MAX(id) AS last_id FROM `$table` WHERE pages_id=:pages_id " .
                "GROUP BY save_id ORDER BY last_id DESC LIMIT " . ($limit + 1) . " OFFSET " . (int) $offset
        );
        $query->bindValue(':pages_id', (int) $page->id, \PDO::PARAM_INT);
        $query->execute();
        $saveIds = $query->fetchAll(\PDO::FETCH_COLUMN, 0);
        if (count($saveIds) > $limit) {
            $hasMore = true;
            $saveIds = array_slice($saveIds, 0, $limit);
        }
        if (empty($saveIds)) return array();

        $saves = array_fill_keys($saveIds, array());
        $placeholders = implode(',', array_fill(0, count($saveIds), '?'));
        $query = $database->prepare(
            "SELECT * FROM `$table` WHERE pages_id=? AND save_id IN ($placeholders) ORDER BY id"
        );
        $query->execute(array_merge(array((int) $page->id), $saveIds));
        while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
            $saves[$row['save_id']][] = $row;
        }

        return $saves;
    }

    /**
     * Render one page of the changelog
     *
     * @param Page $page
     * @param int $offset Number of saves to skip
     * @return string HTML fragment
     */
    protected function renderList(Page $page, $offset = 0)
    {
        $sanitizer = $this->wire('sanitizer');
        $hasMore = false;

        try {
            $saves = $this->findSaves($page, $offset, $hasMore);
        } catch (\Exception $e) {
            $this->wire('log')->save('fw-changelog', "List for page $page->id: " . $e->getMessage());
            return '<p class="fw-changelog-empty">' . $sanitizer->entities($this->_('Could not load the history.')) . '</p>';
        }

        if (empty($saves)) {
            return $offset ? '' : '<p class="fw-changelog-empty">' . $sanitizer->entities($this->_('No changes logged yet.')) . '</p>';
        }

        $out = '';
        foreach ($saves as $rows) {
            if (empty($rows)) continue;
            $first = $rows[0];
            $time = strtotime($first['created']);

            $changes = '';
            foreach ($rows as $row) $changes .= $this->renderChange($row);

            $out .=
                '<div class="fw-changelog-save">' .
                '<div class="fw-changelog-meta">' .
                '<div class="fw-changelog-time">' . date('H:i', $time) . '</div>' .
                '<div class="fw-changelog-date">' . date('d/m/Y', $time) . '</div>' .
                '<div class="fw-changelog-user">' . $this->userLabel((int) $first['users_id'], $first['user_name']) . '</div>' .
                '</div>' .
                "<div class='fw-changelog-changes'>$changes</div>" .
                '</div>';
        }

        if ($hasMore) {
            $out .=
                '<p class="fw-changelog-more"><button type="button" class="ui-button ui-state-default" data-offset="' .
                ($offset + self::SAVES_PER_PAGE) . '"><span class="ui-button-text">' . $sanitizer->entities($this->_('View older changes')) . '</span></button></p>';
        }

        return $out;
    }

    /**
     * User markup already built in this request (id => HTML)
     *
     * @var array
     */
    protected $userLabels = array();

    /**
     * Markup for the user who made a change: the login name, linked to the
     * user's settings in the admin. Users that no longer exist are shown as the
     * login name stored with the entry, without a link.
     *
     * @param int $userId
     * @param string $storedName Login name saved when the change was logged
     * @return string Escaped HTML
     */
    protected function userLabel($userId, $storedName)
    {
        if (isset($this->userLabels[$userId])) return $this->userLabels[$userId];

        $sanitizer = $this->wire('sanitizer');
        $user = $userId ? $this->wire('users')->get($userId) : null;

        if ($user && $user->id) {
            $url = $this->wire('config')->urls->admin . 'access/users/edit/?id=' . (int) $user->id;
            // New tab, so unsaved changes in the page being edited are not lost
            $markup =
                '<a href="' . $sanitizer->entities($url) . '" target="_blank" rel="noopener">' .
                $sanitizer->entities($user->name) . '</a>';
        } else {
            $markup = $sanitizer->entities($storedName !== '' ? $storedName : "#$userId");
        }

        return $this->userLabels[$userId] = $markup;
    }

    /**
     * Render one change: the field label and, below it, the removed value (−, red)
     * stacked over the added value (+, green)
     *
     * @param array $row Database row
     * @return string
     */
    protected function renderChange(array $row)
    {
        $sanitizer = $this->wire('sanitizer');
        $type = $row['type'];

        $label = $sanitizer->entities($this->pathLabel($row['field'], (int) $row['language_id']));
        if ($label === '') $label = $sanitizer->entities($this->_('Page'));
        if ($type === 'file_description') $label .= ' <small>' . $sanitizer->entities($this->_('(description)')) . '</small>';

        // Events with no before/after values: one line, signed when something was added or removed
        $events = array(
            'created' => array('+', $this->_('Page created')),
            'trashed' => array('-', $this->_('Page moved to the trash')),
            'restored' => array('+', $this->_('Page restored from the trash')),
            'file_added' => array('+', (string) $row['new_value']),
            'file_removed' => array('-', (string) $row['old_value']),
            'file_replaced' => array('', $this->_('File replaced')),
            'repeater_added' => array('+', $this->_('Block added')),
            'repeater_removed' => array('-', $this->_('Block removed')),
            'repeater_enabled' => array('', $this->_('Block turned on')),
            'repeater_disabled' => array('', $this->_('Block turned off')),
        );

        if (isset($events[$type])) {
            $values = $this->lineMarkup($events[$type][0], $sanitizer->entities($events[$type][1]));
        } else if ($row['old_value'] === null && $row['new_value'] === null) {
            // Value that is never stored (e.g. passwords)
            $values = $this->lineMarkup('', $sanitizer->entities($this->_('Changed')));
        } else {
            $values = $this->valuesMarkup(
                $this->displayValue($row, (string) $row['old_value']),
                $this->displayValue($row, (string) $row['new_value'])
            );
        }

        if ($row['truncated']) $values .= '<small class="fw-changelog-note">' . $sanitizer->entities($this->_('Value cut because it is too long.')) . '</small>';

        return
            '<div class="fw-changelog-change">' .
            "<div class='fw-changelog-field'>$label</div>" .
            "<div class='fw-changelog-values'>$values</div>" .
            '</div>';
    }

    /**
     * Markup for one stacked line
     *
     * @param string $sign "-" removed (red), "+" added (green), "" neutral remark
     * @param string $html Escaped content
     * @return string
     */
    protected function lineMarkup($sign, $html)
    {
        $classes = array('-' => 'fw-changelog-del', '+' => 'fw-changelog-add', '' => 'fw-changelog-event');
        $symbol = $sign === '-' ? '−' : $sign;

        return
            "<div class='fw-changelog-line {$classes[$sign]}'>" .
            "<span class='fw-changelog-sign' aria-hidden='true'>$symbol</span>" .
            "<span class='fw-changelog-text'>$html</span>" .
            '</div>';
    }

    /**
     * Markup for a before/after pair: old value on top, new value below
     *
     * @param string $before Unescaped old value
     * @param string $after Unescaped new value
     * @return string
     */
    protected function valuesMarkup($before, $after)
    {
        $sanitizer = $this->wire('sanitizer');
        $length = mb_strlen($before) + mb_strlen($after);

        // Paragraph-sized texts read better as one text with the changed words
        // highlighted than as two near-identical blocks
        if ($before !== '' && $after !== '' && $length > 80 && $length <= 6000) {
            return '<div class="fw-changelog-diff">' . $sanitizer->getTextTools()->diffMarkup($before, $after) . '</div>';
        }

        if ($before === '' && $after === '') return $this->lineMarkup('', $sanitizer->entities($this->_('(empty)')));

        $out = '';
        if ($before !== '') $out .= $this->lineMarkup('-', $sanitizer->entities($before));
        if ($after !== '') $out .= $this->lineMarkup('+', $sanitizer->entities($after));

        return $out;
    }

    /**
     * Human-readable version of a stored value (still unescaped)
     *
     * @param array $row Database row
     * @param string $value
     * @return string
     */
    protected function displayValue(array $row, $value)
    {
        if ($row['type'] === 'property') {
            if ($row['field'] === 'status') {
                return strtr($value, array(
                    'unpublished' => $this->_('unpublished'),
                    'published' => $this->_('published'),
                    'hidden' => $this->_('hidden'),
                    'locked' => $this->_('locked'),
                ));
            }
            if ($row['field'] === 'language_active') return $value === '1' ? $this->_('Yes') : $this->_('No');
            return $value;
        }

        // Checkboxes are stored as 1 / empty
        $segments = explode(self::PATH_SEPARATOR, $row['field']);
        $field = $this->wire('fields')->get(end($segments));
        if ($field && wireInstanceOf($field->type, 'FieldtypeCheckbox')) return $value === '1' ? $this->_('Yes') : $this->_('No');

        // Toggles are stored as 0 / 1 / 2: show the label the editor sees instead
        if ($field && $value !== '' && wireInstanceOf($field->type, 'FieldtypeToggle')) return $this->toggleLabel($field, $value);

        return $value;
    }

    /**
     * Label of a Toggle field value, as configured on the field
     * (yes/no, on/off, enabled/disabled or custom labels)
     *
     * @param Field $field Toggle field
     * @param string $value Stored value: "0" no, "1" yes, "2" other
     * @return string The label, or the stored value when it has none
     */
    protected function toggleLabel(Field $field, $value)
    {
        if (!ctype_digit((string) $value)) return $value;

        try {
            /** @var InputfieldToggle $inputfield */
            $inputfield = $this->wire('modules')->get('InputfieldToggle');
            if (!$inputfield) return $value;
            // Label settings live on the field: labelType, yesLabel, noLabel, otherLabel (plus per-language variants)
            foreach ($field->getArray() as $key => $setting) {
                if ($key === 'labelType' || preg_match('/^(yes|no|other)Label\d*$/', $key)) $inputfield->set($key, $setting);
            }
            $label = trim(strip_tags((string) $inputfield->getValueLabel((int) $value)));
        } catch (\Exception $e) {
            return $value;
        }

        return $label !== '' ? $label : $value;
    }

    /**
     * Human-readable label for the "field" column of a row
     *
     * Field names become field labels, "#3" becomes "item 3"; anything else
     * (file names, Combo subfields) is kept as stored.
     *
     * @param string $path Stored path, e.g. "blocks › #3 › body"
     * @param int $languageId
     * @return string Unescaped label
     */
    protected function pathLabel($path, $languageId = 0)
    {
        $properties = array(
            'name' => $this->_('Name (URL)'),
            'status' => $this->_('Status'),
            'parent' => $this->_('Parent page'),
            'template' => $this->_('Template'),
            'language_active' => $this->_('Active in language'),
        );
        $fields = $this->wire('fields');
        $labels = array();

        foreach ($path === '' ? array() : explode(self::PATH_SEPARATOR, $path) as $index => $segment) {
            if (preg_match('/^#(\d+)(?: (.+))?$/', $segment, $m)) {
                // Block type label when one was stored (RepeaterMatrix), position otherwise
                $labels[] = isset($m[2]) && $m[2] !== '' ? $m[2] : sprintf($this->_('item %d'), (int) $m[1]);
            } else if ($index === 0 && isset($properties[$segment]) && !$fields->get($segment)) {
                $labels[] = $properties[$segment];
            } else if ($field = $fields->get($segment)) {
                $labels[] = $field->getLabel();
            } else {
                $labels[] = $segment;
            }
        }

        $label = implode(self::PATH_SEPARATOR, $labels);

        if ($languageId && $this->wire('languages')) {
            $language = $this->wire('languages')->get($languageId);
            if ($language && $language->id) {
                $title = (string) $language->get('title|name');
                $label .= ' (' . ($title !== '' ? $title : $language->name) . ')';
            }
        }

        return $label;
    }

    /**
     * Find the page a change should be logged against
     *
     * Regular pages log against themselves. Repeater items (including RepeaterMatrix,
     * FieldsetPage and nested repeaters) log against the page that owns them, with a
     * path prefix such as "blocks › #3" or "blocks › #3 › links › #1".
     *
     * @param Page $page Page being saved or deleted
     * @return array|null [Page $target, string $prefix], or null when nothing should be logged
     */
    protected function resolveTarget(Page $page)
    {
        $segments = array();
        $item = $page;
        $guard = 0;

        while (wireInstanceOf($item, 'RepeaterPage')) {
            if (++$guard > 10) return null;
            $field = $item->getForField();
            $owner = $item->getForPage();
            if (!$field || !$owner || !$owner->id) return null;
            if (in_array($field->name, (array) $this->ignoredFields)) return null;
            // A FieldsetPage always has exactly one item, so no item number
            $segment = $field->name;
            if (!wireInstanceOf($item, 'FieldsetPage')) $segment .= self::PATH_SEPARATOR . $this->itemSegment($item, $owner, $field);
            array_unshift($segments, $segment);
            $item = $owner;
        }

        if (!$this->shouldLog($item)) return null;

        return array($item, implode(self::PATH_SEPARATOR, $segments));
    }

    /**
     * Path segment identifying one repeater item
     *
     * Always starts with the item position ("#3"). RepeaterMatrix items also carry
     * the label of their block type as it was when the change happened, numbered
     * when the field has more than one block of that type: "#3 Dashboard (2)".
     *
     * @param Page $item Repeater item
     * @param Page $owner Page (or repeater item) that holds the repeater field
     * @param Field $field Repeater field
     * @return string
     */
    protected function itemSegment(Page $item, Page $owner, Field $field)
    {
        $segment = '#' . ((int) $item->sort + 1);
        if (!wireInstanceOf($item, 'RepeaterMatrixPage')) return $segment;

        try {
            $label = trim((string) $item->matrix('label'));
            if ($label === '') return $segment;

            // Position of this item among the items of the same block type
            $type = (int) $item->get('repeater_matrix_type');
            $others = 0;
            $before = 0;
            $siblings = $owner->getUnformatted($field->name);
            if ($siblings instanceof PageArray) {
                foreach ($siblings as $sibling) {
                    if ($sibling->id == $item->id || $this->isReadyItem($sibling)) continue;
                    if ((int) $sibling->get('repeater_matrix_type') !== $type) continue;
                    $others++;
                    if ((int) $sibling->sort < (int) $item->sort) $before++;
                }
            }
            if ($others) $label .= ' (' . ($before + 1) . ')';
        } catch (\Exception $e) {
            return $segment;
        }

        return "$segment $label";
    }

    /**
     * Whether a repeater item is a "ready" placeholder (created ahead of time by the
     * editor, not yet a real item)
     *
     * @param Page $item
     * @return bool
     */
    protected function isReadyItem(Page $item)
    {
        return $item->hasStatus(Page::statusHidden) && $item->hasStatus(Page::statusUnpublished);
    }

    /**
     * Prepend a path prefix to the "field" of every row
     *
     * @param array $rows
     * @param string $prefix
     * @return array
     */
    protected function prefixRows(array $rows, $prefix)
    {
        if ($prefix === '') return $rows;
        foreach ($rows as $key => $row) {
            $rows[$key]['field'] = $prefix . self::PATH_SEPARATOR . (isset($row['field']) ? $row['field'] : '');
        }
        return $rows;
    }

    /**
     * Before a page is deleted: log removed repeater items against their owner page
     *
     * @param HookEvent $event
     */
    public function hookDeleteReady(HookEvent $event)
    {
        /** @var Page $page */
        $page = $event->arguments(0);
        if (!$page->id) return;
        $this->deleting[$page->id] = true;
        if (!wireInstanceOf($page, 'RepeaterPage') || $this->isReadyItem($page)) return;

        // Items deleted only because their owner is being deleted are not worth logging
        $owner = $page->getForPage();
        if (!$owner || !$owner->id || isset($this->deleting[$owner->id])) return;

        $target = $this->resolveTarget($page);
        if (!$target) return;
        list($root, $prefix) = $target;
        if (isset($this->deleting[$root->id])) return;

        $this->insertRows($root, array(array('type' => 'repeater_removed', 'field' => $prefix)));
    }

    /**
     * Whether changes to this page should be logged at all
     *
     * @param Page $page
     * @return bool
     */
    protected function shouldLog(Page $page)
    {
        if (self::$paused) return false;
        // Only changes made by an authenticated user (skips CLI scripts and importers)
        if (!$this->wire('user')->isLoggedin()) return false;
        if (!$page->template) return false;
        // Repeater items are never logged on their own: see resolveTarget()
        if (wireInstanceOf($page, 'RepeaterPage')) return false;
        if (in_array($page->template->name, (array) $this->ignoredTemplates)) return false;
        return true;
    }

    /**
     * Before a full page save: compare against the stored copy and stash the differences
     *
     * @param HookEvent $event
     */
    public function hookSaveReady(HookEvent $event)
    {
        /** @var Page $page */
        $page = $event->arguments(0);
        unset($this->pending[$page->id]);
        if ($page->isNew() || !$page->id) return;

        $target = $this->resolveTarget($page);
        if (!$target) return;
        list($owner, $prefix) = $target;
        $isItem = $prefix !== '';
        // Placeholder repeater items are not real content yet
        if ($isItem && $this->isReadyItem($page)) return;

        $changes = $page->getChanges();
        // Values that are objects (e.g. multi-language text) can change in place
        // without the page listing them, so ask each loaded field as well
        foreach ($page->template->fieldgroup as $field) {
            if (!in_array($field->name, $changes) && $page->isChanged($field->name)) $changes[] = $field->name;
        }
        if (empty($changes)) return;

        $old = $this->wire('pages')->getFresh($page->id);
        if (!$old->id) return;

        // Trashing and restoring rewrite name, parent and status: logged as events instead
        if ($page->isTrash() || $old->isTrash()) return;

        $rows = array();
        $seen = array();
        foreach ($changes as $change) {
            // Multi-language properties are tracked as e.g. "name1234" / "status1234"
            $languageId = 0;
            $name = $change;
            if (preg_match('/^(.+?)(\d+)$/', $change, $m) && !$page->template->fieldgroup->hasField($change)) {
                $name = $m[1];
                $languageId = (int) $m[2];
            }

            $field = $page->template->fieldgroup->getField($name);
            if ($field) {
                if (isset($seen[$field->name])) continue;
                $seen[$field->name] = true;
                $rows = array_merge($rows, $this->diffField($page, $old, $field));
            } else if (!$isItem) {
                // Name, parent and template of repeater items are internal details
                $key = "$name:$languageId";
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $row = $this->diffProperty($page, $old, $name, $languageId);
                if ($row) $rows[] = $row;
            }
        }

        if ($isItem) {
            $rows = $this->prefixRows($rows, $prefix);
            $wasOff = $old->hasStatus(Page::statusUnpublished);
            $isOff = $page->hasStatus(Page::statusUnpublished);
            if ($this->isReadyItem($old)) {
                // Placeholder became a real item: the editor added it
                array_unshift($rows, array('type' => 'repeater_added', 'field' => $prefix));
            } else if ($wasOff !== $isOff) {
                // Item switched on or off in the editor
                array_unshift($rows, array('type' => $isOff ? 'repeater_disabled' : 'repeater_enabled', 'field' => $prefix));
            }
        }

        if ($rows) $this->pending[$page->id] = array('page' => $owner, 'rows' => $rows);
    }

    /**
     * After a successful full page save: write the stashed rows
     *
     * @param HookEvent $event
     */
    public function hookSaved(HookEvent $event)
    {
        $page = $event->arguments(0);
        if (empty($this->pending[$page->id])) return;
        $entry = $this->pending[$page->id];
        unset($this->pending[$page->id]);
        $this->insertRows($entry['page'], $entry['rows']);
    }

    /**
     * Before a single-field save ($page->save('field'))
     *
     * @param HookEvent $event
     */
    public function hookSaveFieldReady(HookEvent $event)
    {
        /** @var Page $page */
        $page = $event->arguments(0);
        $field = $event->arguments(1);
        if (!$field instanceof Field) return;
        $key = "$page->id:$field->name";
        unset($this->pending[$key]);
        if (!$page->id || $page->isTrash()) return;

        $target = $this->resolveTarget($page);
        if (!$target) return;
        list($owner, $prefix) = $target;
        if ($prefix !== '' && $this->isReadyItem($page)) return;

        $old = $this->wire('pages')->getFresh($page->id);
        if (!$old->id) return;

        $rows = $this->prefixRows($this->diffField($page, $old, $field), $prefix);
        if ($rows) $this->pending[$key] = array('page' => $owner, 'rows' => $rows);
    }

    /**
     * After a successful single-field save
     *
     * @param HookEvent $event
     */
    public function hookSavedField(HookEvent $event)
    {
        $page = $event->arguments(0);
        $field = $event->arguments(1);
        if (!$field instanceof Field) return;
        $key = "$page->id:$field->name";
        if (empty($this->pending[$key])) return;
        $entry = $this->pending[$key];
        unset($this->pending[$key]);
        $this->insertRows($entry['page'], $entry['rows']);
    }

    /**
     * Page lifecycle events: created, trashed, restored
     *
     * @param HookEvent $event
     */
    public function hookPageEvent(HookEvent $event)
    {
        $page = $event->arguments(0);
        if (!$page->id || !$this->shouldLog($page)) return;
        $types = array('added' => 'created', 'trashed' => 'trashed', 'restored' => 'restored');
        if (!isset($types[$event->method])) return;
        $this->insertRows($page, array(array('type' => $types[$event->method])));
    }

    /**
     * Compare one field between the page being saved and its stored copy
     *
     * @param Page $page Page being saved
     * @param Page $old Fresh copy loaded from the database
     * @param Field $field
     * @return array Rows to insert (one per changed language, or one for single-language fields)
     */
    protected function diffField(Page $page, Page $old, Field $field)
    {
        if (in_array($field->name, (array) $this->ignoredFields)) return array();
        if (wireInstanceOf($field->type, self::DEFERRED_FIELDTYPES)) return array();
        // Fieldset open/close/tab markers hold no value
        if (wireInstanceOf($field->type, 'FieldtypeFieldsetOpen') || wireInstanceOf($field->type, 'FieldtypeFieldsetClose')) return array();

        $newValue = $page->getUnformatted($field->name);
        $oldValue = $old->getUnformatted($field->name);

        // Never store password values: only record that the field changed
        if (wireInstanceOf($field->type, 'FieldtypePassword')) {
            return array(array('type' => 'field', 'field' => $field->name));
        }

        if (wireInstanceOf($field->type, 'FieldtypeFile')) return $this->diffFiles($field, $oldValue, $newValue);
        if (wireInstanceOf($field->type, 'FieldtypeCombo')) return $this->diffCombo($field, $oldValue, $newValue);

        $rows = array();
        $languages = $this->wire('languages');
        if ($languages && ($newValue instanceof LanguagesPageFieldValue || $oldValue instanceof LanguagesPageFieldValue)) {
            foreach ($languages as $language) {
                $before = $oldValue instanceof LanguagesPageFieldValue ? $oldValue->getLanguageValue($language->id) : ($language->isDefault() ? $oldValue : '');
                $after = $newValue instanceof LanguagesPageFieldValue ? $newValue->getLanguageValue($language->id) : ($language->isDefault() ? $newValue : '');
                $before = $this->valueToString($before, $field);
                $after = $this->valueToString($after, $field);
                if ($before === $after) continue;
                $rows[] = array(
                    'type' => 'field',
                    'field' => $field->name,
                    'language_id' => $language->id,
                    'old_value' => $before,
                    'new_value' => $after,
                );
            }
            return $rows;
        }

        $before = $this->valueToString($oldValue, $field);
        $after = $this->valueToString($newValue, $field);
        if ($before === null && $after === null) {
            // Value type we cannot represent as text: record the change without values
            return array(array('type' => 'field', 'field' => $field->name));
        }
        if ($before === $after) return array();

        return array(array(
            'type' => 'field',
            'field' => $field->name,
            'old_value' => $before,
            'new_value' => $after,
        ));
    }

    /**
     * Compare a file/image field
     *
     * File contents are never stored: only which file was added, removed or replaced,
     * plus description changes (before → after).
     *
     * @param Field $field
     * @param mixed $oldValue Pagefiles from the stored copy
     * @param mixed $newValue Pagefiles from the page being saved
     * @return array
     */
    protected function diffFiles(Field $field, $oldValue, $newValue)
    {
        $before = $this->describeFiles($oldValue);
        $after = $this->describeFiles($newValue);
        $rows = array();

        foreach ($after as $basename => $file) {
            if (!isset($before[$basename])) {
                $rows[] = array('type' => 'file_added', 'field' => $field->name, 'new_value' => $basename);
                continue;
            }
            $path = $field->name . self::PATH_SEPARATOR . $basename;
            if ($before[$basename]['filesize'] !== $file['filesize']) {
                $rows[] = array('type' => 'file_replaced', 'field' => $path);
            }
            foreach ($file['descriptions'] as $languageId => $description) {
                $previous = isset($before[$basename]['descriptions'][$languageId]) ? $before[$basename]['descriptions'][$languageId] : '';
                if ($previous === $description) continue;
                $rows[] = array(
                    'type' => 'file_description',
                    'field' => $path,
                    'language_id' => $languageId,
                    'old_value' => $previous,
                    'new_value' => $description,
                );
            }
        }

        foreach ($before as $basename => $file) {
            if (!isset($after[$basename])) {
                $rows[] = array('type' => 'file_removed', 'field' => $field->name, 'old_value' => $basename);
            }
        }

        return $rows;
    }

    /**
     * Reduce a file field value to the facts we compare, keyed by basename
     *
     * Temporary files (uploaded but page not saved yet) are ignored, so an upload
     * is logged once, when the page is actually saved.
     *
     * @param mixed $value
     * @return array basename => [filesize, descriptions[languageId => text]]
     */
    protected function describeFiles($value)
    {
        $files = array();
        if ($value instanceof Pagefile) $value = array($value);
        if (!$value instanceof Pagefiles && !is_array($value)) return $files;
        $languages = $this->wire('languages');

        foreach ($value as $file) {
            if (!$file instanceof Pagefile || $file->isTemp()) continue;
            $descriptions = array();
            if ($languages) {
                foreach ($languages as $language) {
                    $descriptions[$language->id] = (string) $file->description($language);
                }
            } else {
                $descriptions[0] = (string) $file->description;
            }
            $files[$file->basename] = array(
                'filesize' => (int) $file->filesize(),
                'descriptions' => $descriptions,
            );
        }

        return $files;
    }

    /**
     * Compare a Combo field, one row per changed subfield
     *
     * @param Field $field
     * @param mixed $oldValue ComboValue from the stored copy
     * @param mixed $newValue ComboValue from the page being saved
     * @return array
     */
    protected function diffCombo(Field $field, $oldValue, $newValue)
    {
        $before = $oldValue instanceof WireData ? $oldValue->getArray() : array();
        $after = $newValue instanceof WireData ? $newValue->getArray() : array();
        $rows = array();

        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $subfield) {
            $previous = $this->stringify(isset($before[$subfield]) ? $before[$subfield] : null);
            $current = $this->stringify(isset($after[$subfield]) ? $after[$subfield] : null);
            if ($previous === $current) continue;
            $rows[] = array(
                'type' => 'field',
                'field' => $field->name . self::PATH_SEPARATOR . $subfield,
                'old_value' => $previous,
                'new_value' => $current,
            );
        }

        return $rows;
    }

    /**
     * Compare a built-in page property (name, status, parent, template)
     *
     * @param Page $page Page being saved
     * @param Page $old Fresh copy loaded from the database
     * @param string $name Property name as reported by change tracking, without language id
     * @param int $languageId Language id for per-language names/status, 0 otherwise
     * @return array|null Row to insert, or null when not tracked or unchanged
     */
    protected function diffProperty(Page $page, Page $old, $name, $languageId = 0)
    {
        $before = null;
        $after = null;
        $property = $name;

        if ($name === 'name') {
            $key = $languageId ? "name$languageId" : 'name';
            $before = (string) $old->get($key);
            $after = (string) $page->get($key);
        } else if ($name === 'status' && $languageId) {
            // Whether the page is active in a non-default language
            $property = 'language_active';
            $before = $old->get("status$languageId") ? '1' : '0';
            $after = $page->get("status$languageId") ? '1' : '0';
        } else if ($name === 'status') {
            $before = $this->statusToString($old);
            $after = $this->statusToString($page);
        } else if ($name === 'parent' || $name === 'parent_id') {
            $property = 'parent';
            $before = $old->parent->path . ' (' . $old->parent->id . ')';
            $after = $page->parent->path . ' (' . $page->parent->id . ')';
        } else if ($name === 'template' || $name === 'templates_id') {
            $property = 'template';
            $before = (string) $old->template;
            $after = (string) $page->template;
        } else {
            return null;
        }

        if ($before === $after) return null;

        return array(
            'type' => 'property',
            'field' => $property,
            'language_id' => $languageId,
            'old_value' => $before,
            'new_value' => $after,
        );
    }

    /**
     * Editor-visible status flags as a stable, language-neutral string
     *
     * @param Page $page
     * @return string e.g. "published" or "unpublished, hidden"
     */
    protected function statusToString(Page $page)
    {
        $flags = array($page->hasStatus(Page::statusUnpublished) ? 'unpublished' : 'published');
        if ($page->hasStatus(Page::statusHidden)) $flags[] = 'hidden';
        if ($page->hasStatus(Page::statusLocked)) $flags[] = 'locked';
        return implode(', ', $flags);
    }

    /**
     * Convert an unformatted field value to the text stored in the changelog
     *
     * @param mixed $value
     * @param Field $field
     * @return string|null Null when the value cannot be represented as text
     */
    protected function valueToString($value, Field $field)
    {
        if ($value === null || $value === '' || $value === false) return '';

        if (wireInstanceOf($field->type, 'FieldtypeDatetime')) {
            if (is_numeric($value)) return (int) $value > 0 ? date('Y-m-d H:i:s', (int) $value) : '';
            return (string) $value;
        }

        // Select options: list of option titles
        if (wireInstanceOf($field->type, 'FieldtypeOptions') && $value instanceof WireArray) {
            $titles = array();
            foreach ($value as $option) $titles[] = (string) $option->title;
            return implode(', ', $titles);
        }

        // Page references: "Title (id)" for each selected page, in order
        if (wireInstanceOf($field->type, 'FieldtypePage')) {
            if ($value instanceof Page) $value = array($value);
            if (!$value instanceof PageArray && !is_array($value)) return '';
            $labels = array();
            foreach ($value as $item) {
                if (!$item instanceof Page || !$item->id) continue;
                $title = (string) $item->getUnformatted('title');
                $labels[] = ($title !== '' ? $title : $item->name) . " ($item->id)";
            }
            return implode(', ', $labels);
        }

        return $this->stringify($value);
    }

    /**
     * Generic conversion of a value to text, with no knowledge of the fieldtype
     *
     * @param mixed $value
     * @return string|null Null when the value cannot be represented as text
     */
    protected function stringify($value)
    {
        if ($value === null || $value === '' || $value === false) return '';
        if (is_scalar($value)) return (string) $value;

        // Multi-language values: one "language: text" line per populated language
        if ($value instanceof LanguagesValueInterface && $this->wire('languages')) {
            $lines = array();
            foreach ($this->wire('languages') as $language) {
                $text = (string) $value->getLanguageValue($language->id);
                if ($text !== '') $lines[] = "$language->name: $text";
            }
            return implode("\n", $lines);
        }

        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $json === false ? null : $json;
        }

        if (is_object($value) && method_exists($value, '__toString')) return (string) $value;

        return null;
    }

    /**
     * Write changelog rows for one save of one page
     *
     * A failure here must never break the page save, so errors are logged and swallowed.
     *
     * @param Page $page
     * @param array $rows Each row: type, and optionally field, language_id, old_value, new_value
     */
    protected function insertRows(Page $page, array $rows)
    {
        if (empty($rows)) return;
        $user = $this->wire('user');
        if ($this->saveId === '') $this->saveId = bin2hex(random_bytes(8));
        $saveId = $this->saveId;
        $created = date('Y-m-d H:i:s');
        $max = (int) $this->maxValueLength;

        try {
            $query = $this->wire('database')->prepare(
                'INSERT INTO `' . self::TABLE_NAME . '` ' .
                    '(pages_id, save_id, type, field, language_id, old_value, new_value, truncated, users_id, user_name, created) ' .
                    'VALUES (:pages_id, :save_id, :type, :field, :language_id, :old_value, :new_value, :truncated, :users_id, :user_name, :created)'
            );
            foreach ($rows as $row) {
                $truncated = 0;
                $values = array();
                foreach (array('old_value', 'new_value') as $key) {
                    $value = isset($row[$key]) ? $row[$key] : null;
                    if ($value !== null && $max > 0 && mb_strlen($value) > $max) {
                        $value = mb_substr($value, 0, $max);
                        $truncated = 1;
                    }
                    $values[$key] = $value;
                }
                if ($this->mergeRow($page, $row, $values, $truncated, $created)) continue;

                $query->bindValue(':pages_id', (int) $page->id, \PDO::PARAM_INT);
                $query->bindValue(':save_id', $saveId);
                $query->bindValue(':type', $row['type']);
                $query->bindValue(':field', isset($row['field']) ? $row['field'] : '');
                $query->bindValue(':language_id', isset($row['language_id']) ? (int) $row['language_id'] : 0, \PDO::PARAM_INT);
                $query->bindValue(':old_value', $values['old_value'], $values['old_value'] === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
                $query->bindValue(':new_value', $values['new_value'], $values['new_value'] === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
                $query->bindValue(':truncated', $truncated, \PDO::PARAM_INT);
                $query->bindValue(':users_id', (int) $user->id, \PDO::PARAM_INT);
                $query->bindValue(':user_name', (string) $user->name);
                $query->bindValue(':created', $created);
                $query->execute();
            }
        } catch (\Exception $e) {
            $this->wire('log')->save('fw-changelog', "Page $page->id: " . $e->getMessage());
        }
    }

    /**
     * Module configuration screen
     *
     * @param InputfieldWrapper $inputfields
     */
    public function getModuleConfigInputfields(InputfieldWrapper $inputfields)
    {
        $modules = $this->wire('modules');

        /** @var InputfieldInteger $f */
        $f = $modules->get('InputfieldInteger');
        $f->attr('name', 'retentionMonths');
        $f->label = $this->_('Retention (months)');
        $f->description = $this->_('Entries older than this number of months are deleted automatically.');
        $f->notes = $this->_('0 = keep forever. Automatic cleanup needs the LazyCron module.');
        $f->attr('min', 0);
        $f->attr('value', (int) $this->retentionMonths);
        $f->columnWidth = 33;
        $inputfields->add($f);

        /** @var InputfieldInteger $f */
        $f = $modules->get('InputfieldInteger');
        $f->attr('name', 'maxValueLength');
        $f->label = $this->_('Maximum length per value (characters)');
        $f->description = $this->_('"Before" and "after" values longer than this are cut.');
        $f->notes = $this->_('0 = no limit.');
        $f->attr('min', 0);
        $f->attr('value', (int) $this->maxValueLength);
        $f->columnWidth = 33;
        $inputfields->add($f);

        /** @var InputfieldInteger $f */
        $f = $modules->get('InputfieldInteger');
        $f->attr('name', 'mergeMinutes');
        $f->label = $this->_('Merge consecutive changes (minutes)');
        $f->description = $this->_('Changes by the same user to the same field within this interval are kept as one entry.');
        $f->notes = $this->_('0 = never merge.');
        $f->attr('min', 0);
        $f->attr('value', (int) $this->mergeMinutes);
        $f->columnWidth = 34;
        $inputfields->add($f);

        /** @var InputfieldAsmSelect $f */
        $f = $modules->get('InputfieldAsmSelect');
        $f->attr('name', 'ignoredTemplates');
        $f->label = $this->_('Ignored templates');
        $f->description = $this->_('Pages using these templates are not logged.');
        $f->notes = $this->_('Repeater items are always logged on the page they belong to.');
        foreach ($this->wire('templates') as $template) {
            // Repeater item templates are handled through their owner page
            if (strpos($template->name, 'repeater_') === 0) continue;
            $f->addOption($template->name, $template->name);
        }
        $f->attr('value', (array) $this->ignoredTemplates);
        $f->columnWidth = 50;
        $inputfields->add($f);

        /** @var InputfieldAsmSelect $f */
        $f = $modules->get('InputfieldAsmSelect');
        $f->attr('name', 'ignoredFields');
        $f->label = $this->_('Ignored fields');
        $f->description = $this->_('Changes to these fields are not logged.');
        foreach ($this->wire('fields') as $field) {
            $f->addOption($field->name, $field->name);
        }
        $f->attr('value', (array) $this->ignoredFields);
        $f->columnWidth = 50;
        $inputfields->add($f);
    }

    /**
     * Row types that can be merged with the previous entry for the same field
     */
    const MERGEABLE_TYPES = array('field', 'property', 'file_description');

    /**
     * Try to fold a change into the previous entry instead of adding a new one
     *
     * When the same user changes the same field again within the merge window
     * (e.g. several quick saves, or an autosave), the existing entry keeps its
     * original "before" value and time, and only its "after" value is updated.
     * If the field ends up back at its original value, the entry is removed.
     *
     * @param Page $page Page the row is logged against
     * @param array $row Row about to be inserted
     * @param array $values Already truncated old_value / new_value
     * @param int $truncated Whether either value was truncated
     * @param string $now Current time, Y-m-d H:i:s
     * @return bool True when merged (nothing left to insert)
     */
    protected function mergeRow(Page $page, array $row, array $values, $truncated, $now)
    {
        $minutes = (int) $this->mergeMinutes;
        if ($minutes <= 0 || empty($row['field']) || !in_array($row['type'], self::MERGEABLE_TYPES)) return false;
        if ($values['old_value'] === null || $values['new_value'] === null) return false;

        $database = $this->wire('database');
        $table = self::TABLE_NAME;

        // Latest entry for this field, whoever made it: a change by someone else in between prevents merging
        $query = $database->prepare(
            "SELECT id, type, users_id, old_value, created FROM `$table` " .
                "WHERE pages_id=:pages_id AND field=:field AND language_id=:language_id ORDER BY id DESC LIMIT 1"
        );
        $query->bindValue(':pages_id', (int) $page->id, \PDO::PARAM_INT);
        $query->bindValue(':field', $row['field']);
        $query->bindValue(':language_id', isset($row['language_id']) ? (int) $row['language_id'] : 0, \PDO::PARAM_INT);
        $query->execute();
        $last = $query->fetch(\PDO::FETCH_ASSOC);
        $query->closeCursor();

        if (!$last || $last['type'] !== $row['type'] || $last['old_value'] === null) return false;
        if ((int) $last['users_id'] !== (int) $this->wire('user')->id) return false;
        if (strtotime($now) - strtotime($last['created']) > $minutes * 60) return false;

        if ($last['old_value'] === $values['new_value']) {
            // Back to where it started: nothing changed overall
            $query = $database->prepare("DELETE FROM `$table` WHERE id=:id");
            $query->bindValue(':id', (int) $last['id'], \PDO::PARAM_INT);
            $query->execute();
            return true;
        }

        $query = $database->prepare("UPDATE `$table` SET new_value=:new_value, truncated=GREATEST(truncated, :truncated) WHERE id=:id");
        $query->bindValue(':new_value', $values['new_value']);
        $query->bindValue(':truncated', (int) $truncated, \PDO::PARAM_INT);
        $query->bindValue(':id', (int) $last['id'], \PDO::PARAM_INT);
        $query->execute();

        return true;
    }

    /**
     * Whether logging is switched off for the rest of this request
     *
     * @var bool
     */
    protected static $paused = false;

    /**
     * Stop logging for the rest of this request (or until resume() is called).
     *
     * For scripts that change many pages while a user is logged in, e.g. bulk
     * updates run from the admin:
     *
     *   FwChangelog::pause();
     *   // ... bulk changes ...
     *   FwChangelog::resume();
     */
    public static function pause()
    {
        self::$paused = true;
    }

    /**
     * Start logging again after pause()
     */
    public static function resume()
    {
        self::$paused = false;
    }

    /**
     * Whether logging is currently paused
     *
     * @return bool
     */
    public static function isPaused()
    {
        return self::$paused;
    }

    /**
     * Daily cleanup (LazyCron): delete entries older than the retention period
     *
     * Deletes in batches so the table is never locked for long.
     *
     * @param HookEvent|null $event
     * @return int Number of rows deleted
     */
    public function cleanup($event = null)
    {
        $months = (int) $this->retentionMonths;
        if ($months <= 0) return 0;

        $cutoff = date('Y-m-d H:i:s', strtotime("-$months months"));
        $table = self::TABLE_NAME;
        $deleted = 0;

        try {
            $query = $this->wire('database')->prepare("DELETE FROM `$table` WHERE created < :cutoff LIMIT 5000");
            $query->bindValue(':cutoff', $cutoff);
            // At most 100,000 rows per run: anything left is handled the next day
            for ($batch = 0; $batch < 20; $batch++) {
                $query->execute();
                $count = $query->rowCount();
                $deleted += $count;
                if ($count < 5000) break;
            }
        } catch (\Exception $e) {
            $this->wire('log')->save('fw-changelog', 'Cleanup: ' . $e->getMessage());
        }

        if ($deleted) $this->wire('log')->save('fw-changelog', "Cleanup: deleted $deleted entries older than $cutoff");

        return $deleted;
    }

    /**
     * Create the changelog table
     *
     * @throws WireException if the table cannot be created
     */
    public function ___install()
    {
        $config = $this->wire('config');
        $engine = $config->dbEngine ?: 'InnoDB';
        $charset = $config->dbCharset ?: 'utf8mb4';
        $table = self::TABLE_NAME;

        $this->wire('database')->exec("
            CREATE TABLE IF NOT EXISTS `$table` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `pages_id` INT UNSIGNED NOT NULL,
                `save_id` CHAR(16) NOT NULL,
                `type` VARCHAR(32) NOT NULL DEFAULT 'field',
                `field` VARCHAR(255) NOT NULL DEFAULT '',
                `language_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `old_value` MEDIUMTEXT NULL,
                `new_value` MEDIUMTEXT NULL,
                `truncated` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `user_name` VARCHAR(128) NOT NULL DEFAULT '',
                `created` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                INDEX `pages_created` (`pages_id`, `created`),
                INDEX `created` (`created`)
            ) ENGINE=$engine DEFAULT CHARSET=$charset
        ");
    }

    /**
     * Drop the changelog table (all history is lost)
     */
    public function ___uninstall()
    {
        $this->wire('database')->exec('DROP TABLE IF EXISTS `' . self::TABLE_NAME . '`');
    }

    /**
     * Schema changes between module versions go here
     *
     * @param int|string $fromVersion
     * @param int|string $toVersion
     */
    public function ___upgrade($fromVersion, $toVersion) {}
}
