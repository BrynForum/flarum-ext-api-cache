import app from 'flarum/admin/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';

export default class RuleModal extends Modal {
    oninit(vnode) {
        super.oninit(vnode);

        const a = (this.attrs.rule && this.attrs.rule.attributes) || {};

        this.name = Stream(a.name || '');
        this.pathPattern = Stream(a.pathPattern || '');
        this.queryFilter = Stream(a.queryFilter || '');
        this.ttlSeconds = Stream(a.ttlSeconds || 600);
        this.scope = Stream(a.scope || 'public');
        this.enabled = Stream(typeof a.enabled === 'boolean' ? a.enabled : true);
        this.priority = Stream(a.priority || 0);
        this.seedFromAuthenticated = Stream(
            typeof a.seedFromAuthenticated === 'boolean' ? a.seedFromAuthenticated : true,
        );
    }

    className() {
        return 'Modal--small';
    }

    title() {
        return this.attrs.rule ? 'Edit rule' : 'New rule';
    }

    content() {
        return m('.Modal-body', m('.Form', [
            m('.Form-group', [
                m('label', 'Name'),
                m('input.FormControl', {
                    type: 'text',
                    value: this.name(),
                    oninput: (e) => this.name(e.target.value),
                    placeholder: 'e.g. Top-poster widget',
                }),
            ]),
            m('.Form-group', [
                m('label', 'Path pattern (PCRE regex)'),
                m('input.FormControl', {
                    type: 'text',
                    value: this.pathPattern(),
                    oninput: (e) => this.pathPattern(e.target.value),
                    placeholder: '#^/api/users$#',
                }),
                m('p.helpText', [
                    'Must include delimiters. Examples: ',
                    m('code', '#^/api/users$#'),
                    ', ',
                    m('code', '#^/api/discussions#'),
                ]),
            ]),
            m('.Form-group', [
                m('label', 'Query filter (optional regex)'),
                m('input.FormControl', {
                    type: 'text',
                    value: this.queryFilter(),
                    oninput: (e) => this.queryFilter(e.target.value),
                    placeholder: '#filter\\[top_poster\\]=true#',
                }),
                m('p.helpText', 'Empty = match any querystring.'),
            ]),
            m('.Form-group', [
                m('label', 'TTL (seconds)'),
                m('input.FormControl', {
                    type: 'number',
                    min: 1,
                    max: 604800,
                    value: this.ttlSeconds(),
                    oninput: (e) => this.ttlSeconds(parseInt(e.target.value, 10) || 0),
                }),
            ]),
            m('.Form-group', [
                m('label', 'Scope'),
                m('select.FormControl', {
                    value: this.scope(),
                    onchange: (e) => this.scope(e.target.value),
                }, [
                    m('option', { value: 'public' }, 'public — shared across everyone'),
                    m('option', { value: 'guest' }, 'guest — only used when unauthenticated'),
                ]),
            ]),
            m('.Form-group', [
                m('label', 'Priority'),
                m('input.FormControl', {
                    type: 'number',
                    value: this.priority(),
                    oninput: (e) => this.priority(parseInt(e.target.value, 10) || 0),
                }),
                m('p.helpText', 'Higher = checked first. Use to break ties when multiple rules match.'),
            ]),
            m('.Form-group', [
                m('label', [
                    m('input', {
                        type: 'checkbox',
                        checked: this.enabled(),
                        onchange: (e) => this.enabled(e.target.checked),
                    }),
                    ' Enabled',
                ]),
            ]),
            m('.Form-group', [
                m('label', [
                    m('input', {
                        type: 'checkbox',
                        checked: this.seedFromAuthenticated(),
                        onchange: (e) => this.seedFromAuthenticated(e.target.checked),
                    }),
                    ' Allow authenticated requests to seed the cache',
                ]),
                m('p.helpText', [
                    'Uncheck for endpoints where the response may differ for authenticated users ',
                    '(e.g. ', m('code', '/api/users'), ' includes ', m('code', 'email'), ' for admins). ',
                    'When unchecked, the cache is only populated by requests from logged-out visitors; ',
                    'everyone can still ', m('em', 'read'), ' the cached response. Default on for backward compatibility.',
                ]),
            ]),
            m('.Form-group', Button.component(
                {
                    type: 'submit',
                    className: 'Button Button--primary',
                    loading: this.loading,
                    onclick: () => this.onsubmit(),
                },
                this.attrs.rule ? 'Save' : 'Create'
            )),
        ]));
    }

    onsubmit(e) {
        if (e && e.preventDefault) e.preventDefault();

        this.loading = true;

        const attributes = {
            name: this.name(),
            pathPattern: this.pathPattern(),
            queryFilter: this.queryFilter() || null,
            ttlSeconds: this.ttlSeconds(),
            scope: this.scope(),
            enabled: this.enabled(),
            priority: this.priority(),
            seedFromAuthenticated: this.seedFromAuthenticated(),
        };

        const id = this.attrs.rule ? this.attrs.rule.id : null;

        return app
            .request({
                method: id ? 'PATCH' : 'POST',
                url: app.forum.attribute('apiUrl') + '/api-cache-rules' + (id ? '/' + id : ''),
                body: { data: { type: 'api-cache-rules', attributes } },
            })
            .then(() => {
                this.loading = false;
                this.attrs.onsave && this.attrs.onsave();
                this.hide();
            })
            .catch((err) => {
                this.loading = false;
                m.redraw();
                throw err;
            });
    }
}
