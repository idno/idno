#!/bin/sh
set -ex

if [ -f docker/init.sh ]; then
    cd docker
fi

echo Building image
docker build -f Dockerfile .. -t jimwins/known

echo Creating temporary container to extract 'vendor'
TMP_CONTAINER=known.$$
docker create --name ${TMP_CONTAINER} jimwins/known

echo Extracting 'vendor'
docker cp ${TMP_CONTAINER}:/app/vendor ..

echo Cleaning up
docker rm ${TMP_CONTAINER}

echo Making configuration, Uploads and var writeable
# var/ holds the compiled container (var/cache), which the web server must be able to write.
mkdir -p ../var
chmod 0777 ../configuration ../Uploads ../var

echo done.
