<div class="form-group">
    <label><?= $view['translator']->trans('API URL') ?></label>
    <input type="text" name="api_url" class="form-control" value="<?= $integration->getApiUrl() ?>" />
</div>

<div class="form-group">
    <label><?= $view['translator']->trans('API Key') ?></label>
    <input type="text" name="api_key" class="form-control" value="<?= $integration->getApiKey() ?>" />
</div>

