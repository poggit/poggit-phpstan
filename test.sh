#!/bin/sh

ROOT_PATH="$PWD"
echo "docker run -it -v /$ROOT_PATH:/source jaxkdev/poggit-phpstan:0.0.1"
read -n 1