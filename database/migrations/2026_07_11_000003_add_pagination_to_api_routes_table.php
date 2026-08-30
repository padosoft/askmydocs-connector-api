<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pagination config on api_routes (spec items 4-5 — the workbench "Paginazione").
 *
 * A nullable JSON blob (precedent: `output_transform`) holding how the endpoint
 * paginates so the tester can walk pages and the tool can fetch beyond page 1:
 *   { "type": "page|cursor|none",
 *     "page_param": "page", "size_param": "per_page", "start_page": 1,   // page
 *     "cursor_param": "cursor", "next_cursor_path": "meta.next_cursor",  // cursor (in body)
 *     "next_url_path": "links.next",                                     // or a full next-URL
 *     "items_path": "data" }                                            // where the collection is
 *
 * Legacy rows keep pagination=null (unpaginated). Auto-detected (heuristic + AI
 * fallback) then confirmed/edited + saved by the operator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_routes', function (Blueprint $table) {
            // ->after() is a MySQL readability nicety and a no-op on SQLite.
            $table->json('pagination')->nullable()->after('output_transform');
        });
    }

    public function down(): void
    {
        Schema::table('api_routes', function (Blueprint $table) {
            $table->dropColumn('pagination');
        });
    }
};
