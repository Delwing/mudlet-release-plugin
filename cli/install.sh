#!/bin/bash

if ! wp core is-installed; then
    wp core install --url=localhost --title=\"Mudlet\" --admin_user=admin --admin_password=admin --admin_email=admin@mudlet.org
    wp plugin install polylang --activate
    wp plugin activate mudlet-release
fi
wp server --host=0.0.0.0 --port=80