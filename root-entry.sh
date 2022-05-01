#!/usr/bin/env bash

chown pocketmine:pocketmine /source/plugin.zip
su -c "php -dphar.readonly=0 /usr/bin/entry" pocketmine
