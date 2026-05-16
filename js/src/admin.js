import app from 'flarum/admin/app';
import ApiCachePane from './admin/components/ApiCachePane';

app.initializers.add('brynforum-api-cache', () => {
    app.extensionData
        .for('brynforum-api-cache')
        .registerPage(ApiCachePane);
});
