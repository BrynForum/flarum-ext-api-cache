import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import RuleModal from './RuleModal';

export default class ApiCachePane extends ExtensionPage {
    oninit(vnode) {
        super.oninit(vnode);
        this.rules = null;
        this.loading = true;
        this.flushing = false;
        this.loadRules();
    }

    loadRules() {
        this.loading = true;
        m.redraw();

        return app
            .request({
                method: 'GET',
                url: app.forum.attribute('apiUrl') + '/api-cache-rules',
            })
            .then((response) => {
                this.rules = response.data || [];
                this.loading = false;
                m.redraw();
            })
            .catch(() => {
                this.rules = [];
                this.loading = false;
                m.redraw();
            });
    }

    content() {
        return m('.ExtensionPage-settings', m('.container', [
            m('.Form-group', [
                m('h3', 'Cache rules'),
                m('p.helpText', [
                    'Each rule matches GET requests by path regex and optional querystring regex. ',
                    'First match wins, ordered by priority (high first). ',
                    'Patterns must include PCRE delimiters, e.g. ',
                    m('code', '#^/api/users$#'),
                    '.',
                ]),
                this.loading
                    ? m(LoadingIndicator)
                    : this.renderRulesTable(),
                m('.ButtonGroup', { style: 'margin-top: 12px;' }, [
                    Button.component(
                        {
                            className: 'Button Button--primary',
                            onclick: () => this.openModal(null),
                        },
                        'Add rule'
                    ),
                    Button.component(
                        {
                            className: 'Button',
                            loading: this.flushing,
                            onclick: () => this.flushCache(),
                        },
                        'Clear all cache'
                    ),
                ]),
            ]),
        ]));
    }

    renderRulesTable() {
        if (!this.rules || this.rules.length === 0) {
            return m('p', m('em', 'No rules yet. Click "Add rule" to create one.'));
        }

        return m('table.Table', [
            m('thead', m('tr', [
                m('th', 'Name'),
                m('th', 'Path pattern'),
                m('th', 'Query filter'),
                m('th', 'TTL'),
                m('th', 'Scope'),
                m('th', 'Priority'),
                m('th', 'On'),
                m('th', ''),
            ])),
            m('tbody', this.rules.map((row) => this.renderRow(row))),
        ]);
    }

    renderRow(row) {
        const a = row.attributes || {};
        return m('tr', { key: row.id }, [
            m('td', a.name),
            m('td', m('code', a.pathPattern)),
            m('td', a.queryFilter ? m('code', a.queryFilter) : m('span.helpText', '—')),
            m('td', a.ttlSeconds + 's'),
            m('td', a.scope),
            m('td', a.priority),
            m('td', a.enabled ? '✓' : '—'),
            m('td', [
                Button.component(
                    {
                        className: 'Button Button--icon Button--text',
                        icon: 'fas fa-pencil-alt',
                        onclick: () => this.openModal(row),
                    }
                ),
                Button.component(
                    {
                        className: 'Button Button--icon Button--text',
                        icon: 'fas fa-trash',
                        onclick: () => this.deleteRule(row),
                    }
                ),
            ]),
        ]);
    }

    openModal(rule) {
        app.modal.show(RuleModal, {
            rule,
            onsave: () => this.loadRules(),
        });
    }

    deleteRule(row) {
        if (!confirm('Delete rule "' + (row.attributes && row.attributes.name) + '"?')) {
            return;
        }

        app.request({
            method: 'DELETE',
            url: app.forum.attribute('apiUrl') + '/api-cache-rules/' + row.id,
        }).then(() => this.loadRules());
    }

    flushCache() {
        this.flushing = true;
        m.redraw();

        app.request({
            method: 'POST',
            url: app.forum.attribute('apiUrl') + '/api-cache-rules/flush',
        }).then(() => {
            this.flushing = false;
            app.alerts.show({ type: 'success' }, 'Cache flushed.');
            m.redraw();
        }).catch(() => {
            this.flushing = false;
            m.redraw();
        });
    }
}
