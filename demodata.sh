#!/bin/bash

php artisan migrate:fresh --force --seed
php artisan db:seed --class=DemoSeeder --force
