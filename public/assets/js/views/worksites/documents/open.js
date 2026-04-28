    const config = <?= json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    new DocsAPI.DocEditor("editor", config);
