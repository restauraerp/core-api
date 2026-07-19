#!/bin/bash

php artisan migrate:fresh --seed && php artisan db:seed DemoData
