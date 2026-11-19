<?php

namespace ProcessWire;

/**
 * ProcessFwChangelog
 *
 * Dashboard for FwChangelog: lists the changes logged across all pages, newest
 * first, with filters by user, date range, template and branch of the page tree.
 */
class ProcessFwChangelog extends Process
{

    /**
     * Number of saves listed per page of results
     */
    const PER_PAGE = 25;

    /**
     * List the logged changes that match the filters in the query string
     *
     * @return string
     */
    public function ___execute()
    {
        /** @var FwChangelog $changelog */
        $changelog = $this->wire('modules')->get('FwChangelog');
        $config = $this->wire('config');
        $sanitizer = $this->wire('sanitizer');

        $file = __DIR__ . '/FwChangelog.css';
        $config->styles->add($config->urls->get('FwChangelog') . 'FwChangelog.css?v=' . (int) @filemtime($file));

        $filters = $this->readFilters();
        $start = max(0, (int) $this->wire('input')->get('start'));
        $hasMore = false;

        try {
            $saves = $this->findSaves($filters, $start, $hasMore);
        } catch (\Exception $e) {
            $this->wire('log')->save('fw-changelog', 'Dashboard: ' . $e->getMessage());
            $this->error($this->_('Could not load the history.'));
            $saves = array();
        }

        $out = $this->buildFilterForm($filters)->render();

        if (empty($saves)) {
            $out .= '<p class="fw-changelog-empty">' . $sanitizer->entities($this->_('No changes match these filters.')) . '</p>';
            return $out;
        }

        $out .= '<div class="fw-changelog fw-changelog-dashboard">' . $changelog->renderSaves($saves, true) . '</div>';
        $out .= $this->renderPager($filters, $start, $hasMore);

        return $out;
    }

    /**
     * Read and sanitize the filters from the query string
     *
     * @return array user, template, from, to (Y-m-d or ''), branch
     */
    protected function readFilters()
    {
        $input = $this->wire('input');
        $filters = array(
            'user' => (int) $input->get('user'),
            'template' => (int) $input->get('template'),
            'from' => (string) $input->get('from'),
            'to' => (string) $input->get('to'),
            'branch' => (int) $input->get('branch'),
        );

        foreach (array('from', 'to') as $key) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key])) $filters[$key] = '';
        }

        // The whole tree is the same as no branch filter
        if ($filters['branch'] === 1) $filters['branch'] = 0;

        return $filters;
    }

    /**
     * Find one page of results: groups of rows, one group per save of one page
     *
     * @param array $filters From readFilters()
     * @param int $start Number of groups to skip
     * @param bool $hasMore Set to true when older groups exist beyond this page
     * @return array Groups of database rows, newest first
     */
    protected function findSaves(array $filters, $start, &$hasMore)
    {
        $database = $this->wire('database');
        $table = FwChangelog::TABLE_NAME;
        $where = array();
        $bind = array();

        if ($filters['user']) {
            $where[] = 'c.users_id=:user';
            $bind[':user'] = $filters['user'];
        }
        if ($filters['template']) {
            $where[] = 'c.templates_id=:template';
            $bind[':template'] = $filters['template'];
        }
        if ($filters['from'] !== '') {
            $where[] = 'c.created>=:from';
            $bind[':from'] = $filters['from'] . ' 00:00:00';
        }
        if ($filters['to'] !== '') {
            $where[] = 'c.created<=:to';
            $bind[':to'] = $filters['to'] . ' 23:59:59';
        }
        if ($filters['branch']) {
            // The branch page, its children, and the children of every page below it
            // that has children (pages_parents lists those, with all their ancestors)
            $where[] =
                'c.pages_id IN (SELECT p.id FROM pages AS p WHERE p.id=:b1 OR p.parent_id=:b2 OR p.parent_id IN ' .
                '(SELECT pp.pages_id FROM pages_parents AS pp WHERE pp.parents_id=:b3))';
            $bind[':b1'] = $bind[':b2'] = $bind[':b3'] = $filters['branch'];
        }

        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit = self::PER_PAGE;

        // One more than needed tells whether there is an older page, without counting everything
        $query = $database->prepare(
            "SELECT c.save_id, c.pages_id, MAX(c.id) AS last_id FROM `$table` AS c $sqlWhere " .
            'GROUP BY c.save_id, c.pages_id ORDER BY last_id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . (int) $start
        );
        foreach ($bind as $key => $value) {
            $query->bindValue($key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $query->execute();
        $groups = $query->fetchAll(\PDO::FETCH_ASSOC);
        $query->closeCursor();

        $hasMore = count($groups) > $limit;
        $groups = array_slice($groups, 0, $limit);
        if (empty($groups)) return array();

        $saves = array();
        $saveIds = array();
        $pageIds = array();
        foreach ($groups as $group) {
            $saves[$group['save_id'] . ':' . $group['pages_id']] = array();
            $saveIds[$group['save_id']] = $group['save_id'];
            $pageIds[(int) $group['pages_id']] = (int) $group['pages_id'];
        }

        $query = $database->prepare(
            "SELECT * FROM `$table` WHERE save_id IN (" . implode(',', array_fill(0, count($saveIds), '?')) . ') ' .
            'AND pages_id IN (' . implode(',', array_fill(0, count($pageIds), '?')) . ') ORDER BY id'
        );
        $query->execute(array_merge(array_values($saveIds), array_values($pageIds)));
        while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
            $key = $row['save_id'] . ':' . $row['pages_id'];
            if (isset($saves[$key])) $saves[$key][] = $row;
        }

        return $saves;
    }

    /**
     * Build the filters form (sent with GET, so results can be bookmarked and shared)
     *
     * @param array $filters Current filters
     * @return InputfieldForm
     */
    protected function buildFilterForm(array $filters)
    {
        $modules = $this->wire('modules');

        /** @var InputfieldForm $form */
        $form = $modules->get('InputfieldForm');
        $form->attr('method', 'get');
        $form->attr('action', './');
        $form->attr('id', 'fw-changelog-filters');
        $form->protectCSRF = false;

        /** @var InputfieldFieldset $fieldset */
        $fieldset = $modules->get('InputfieldFieldset');
        $fieldset->label = $this->_('Filters');
        $fieldset->icon = 'filter';
        $active = array_filter($filters);
        if (!$active) $fieldset->collapsed = Inputfield::collapsedYes;
        $form->add($fieldset);

        /** @var InputfieldSelect $f */
        $f = $modules->get('InputfieldSelect');
        $f->attr('name', 'user');
        $f->label = $this->_('User');
        foreach ($this->loggedUsers() as $id => $name) $f->addOption($id, $name);
        $f->attr('value', $filters['user'] ?: '');
        $f->columnWidth = 25;
        $fieldset->add($f);

        /** @var InputfieldSelect $f */
        $f = $modules->get('InputfieldSelect');
        $f->attr('name', 'template');
        $f->label = $this->_('Template');
        foreach ($this->loggedTemplates() as $id => $name) $f->addOption($id, $name);
        $f->attr('value', $filters['template'] ?: '');
        $f->columnWidth = 25;
        $fieldset->add($f);

        foreach (array('from' => $this->_('From'), 'to' => $this->_('To')) as $name => $label) {
            /** @var InputfieldDatetime $f */
            $f = $modules->get('InputfieldDatetime');
            $f->attr('name', $name);
            $f->label = $label;
            $f->inputType = 'html';
            $f->htmlType = 'date';
            if ($filters[$name] !== '') $f->attr('value', strtotime($filters[$name]));
            $f->columnWidth = 25;
            $fieldset->add($f);
        }

        /** @var InputfieldPageListSelect $f */
        $f = $modules->get('InputfieldPageListSelect');
        $f->attr('name', 'branch');
        $f->label = $this->_('Branch');
        $f->description = $this->_('Only changes to this page and the pages below it.');
        $f->attr('value', $filters['branch'] ?: '');
        $fieldset->add($f);

        /** @var InputfieldSubmit $f */
        $f = $modules->get('InputfieldSubmit');
        $f->attr('name', 'filter');
        $f->attr('value', $this->_('Filter'));
        $f->icon = 'filter';
        if ($active) {
            $f->appendMarkup =
                ' <a class="fw-changelog-reset" href="./">' . $this->wire('sanitizer')->entities($this->_('Clear filters')) . '</a>';
        }
        $fieldset->add($f);

        return $form;
    }

    /**
     * Users that appear in the log, for the user filter
     *
     * @return array users_id => name
     */
    protected function loggedUsers()
    {
        $options = array();
        $rows = $this->wire('database')->query(
            'SELECT users_id, MAX(user_name) FROM `' . FwChangelog::TABLE_NAME . '` GROUP BY users_id'
        )->fetchAll(\PDO::FETCH_NUM);

        foreach ($rows as $row) {
            $user = $this->wire('users')->get((int) $row[0]);
            $options[(int) $row[0]] = $user->id ? $user->name : ($row[1] !== '' ? $row[1] : '#' . (int) $row[0]);
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /**
     * Templates that appear in the log, for the template filter
     *
     * @return array templates_id => name
     */
    protected function loggedTemplates()
    {
        $options = array();
        $ids = $this->wire('database')->query(
            'SELECT DISTINCT templates_id FROM `' . FwChangelog::TABLE_NAME . '` WHERE templates_id>0'
        )->fetchAll(\PDO::FETCH_COLUMN, 0);

        foreach ($ids as $id) {
            $template = $this->wire('templates')->get((int) $id);
            if ($template) $options[(int) $id] = $template->name;
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /**
     * Links to newer and older results, keeping the filters
     *
     * @param array $filters
     * @param int $start
     * @param bool $hasMore
     * @return string
     */
    protected function renderPager(array $filters, $start, $hasMore)
    {
        $sanitizer = $this->wire('sanitizer');
        $query = array_filter($filters);
        $links = array();

        if ($start > 0) {
            $query['start'] = max(0, $start - self::PER_PAGE);
            if (!$query['start']) unset($query['start']);
            $links[] = '<a class="ui-button ui-state-default" href="./' . ($query ? '?' . $sanitizer->entities(http_build_query($query)) : '') . '">' .
                '<span class="ui-button-text">' . $sanitizer->entities($this->_('Newer changes')) . '</span></a>';
        }
        if ($hasMore) {
            $query['start'] = $start + self::PER_PAGE;
            $links[] = '<a class="ui-button ui-state-default" href="./?' . $sanitizer->entities(http_build_query($query)) . '">' .
                '<span class="ui-button-text">' . $sanitizer->entities($this->_('Older changes')) . '</span></a>';
        }

        return $links ? '<p class="fw-changelog-more fw-changelog-pager">' . implode(' ', $links) . '</p>' : '';
    }
}
