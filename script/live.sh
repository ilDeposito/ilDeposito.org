#!/bin/bash

comando=$1

cd ..

if [ $comando = "up" ]; then
    docker-compose up -d
fi

if [ $comando = "restart" ]; then
    docker-compose stop
    docker-compose up -d
fi

if [ $comando = "stop" ]; then
    docker-compose stop
fi

if [ $comando = "down" ]; then
    docker-compose stop
    docker-compose down
fi

if [ $comando = "exec" ]; then
    docker-compose exec php /bin/bash
fi

if [ $comando = "ps" ]; then
    docker-compose ps
fi


