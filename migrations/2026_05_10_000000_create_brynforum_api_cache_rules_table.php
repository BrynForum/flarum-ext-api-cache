<?php

use Flarum\Database\Migration;

return Migration::createTable(
    'brynforum_api_cache_rules',
    function (\Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('name', 200);
        $table->string('path_pattern', 500);
        $table->string('query_filter', 500)->nullable();
        $table->unsignedInteger('ttl_seconds')->default(300);
        $table->enum('scope', ['public', 'guest'])->default('public');
        $table->boolean('enabled')->default(true);
        $table->unsignedInteger('priority')->default(0);
        $table->timestamps();

        $table->index(['enabled', 'priority']);
    }
);
