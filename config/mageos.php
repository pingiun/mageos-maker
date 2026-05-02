<?php

return [

    'catalog_url' => env('MAGEOS_CATALOG_URL', 'https://repo.mage-os.org/packages.json'),

    // Used as the `repositories` entry in the generated composer.json.
    'repository_url' => env('MAGEOS_REPOSITORY_URL', 'https://repo.mage-os.org/'),

    // The package whose versions are exposed in the version dropdown.
    'edition_package' => 'mage-os/project-community-edition',

    // Filesystem paths (under storage/app/private when using the local disk)
    'cache_dir' => 'mageos-catalog',
    'graphs_dir' => 'graphs',
    'packagist_cache_dir' => 'packagist-cache',

    // Where YAML definitions live (relative to base_path()).
    'definitions_path' => 'definitions',

    // Fallback shipped with the tool, used when the catalog cache is empty.
    'fallback_version' => '2.2.2',
];
