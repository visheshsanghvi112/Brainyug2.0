<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('states', function (Blueprint $table) {
            $table->string('abbreviation', 5)->nullable()->after('name');
        });

        // Seed official Indian state abbreviations (ISO 3166-2:IN)
        $abbreviations = [
            1  => 'JK',  // Jammu & Kashmir
            2  => 'HP',  // Himachal Pradesh
            3  => 'PB',  // Punjab
            4  => 'CH',  // Chandigarh
            5  => 'UK',  // Uttaranchal / Uttarakhand
            6  => 'HR',  // Haryana
            7  => 'DL',  // Delhi
            8  => 'RJ',  // Rajasthan
            9  => 'UP',  // Uttar Pradesh
            10 => 'BR',  // Bihar
            11 => 'SK',  // Sikkim
            12 => 'AR',  // Arunachal Pradesh
            13 => 'NL',  // Nagaland
            14 => 'MN',  // Manipur
            15 => 'MZ',  // Mizoram
            16 => 'TR',  // Tripura
            17 => 'ML',  // Meghalaya
            18 => 'AS',  // Assam
            19 => 'WB',  // West Bengal
            20 => 'JH',  // Jharkhand
            21 => 'OR',  // Orissa / Odisha
            22 => 'CG',  // Chhattisgarh
            23 => 'MP',  // Madhya Pradesh
            24 => 'GJ',  // Gujarat
            25 => 'DD',  // Daman & Diu
            26 => 'DN',  // Dadra & Nagar Haveli
            27 => 'MH',  // Maharashtra
            28 => 'AP',  // Andhra Pradesh
            29 => 'KA',  // Karnataka
            30 => 'GA',  // Goa
            31 => 'LD',  // Lakshadweep
            32 => 'KL',  // Kerala
            33 => 'TN',  // Tamil Nadu
            34 => 'PY',  // Pondicherry / Puducherry
            35 => 'AN',  // Andaman & Nicobar Islands
            36 => 'TS',  // Telangana
        ];

        foreach ($abbreviations as $id => $abbr) {
            DB::table('states')->where('id', $id)->update(['abbreviation' => $abbr]);
        }
    }

    public function down(): void
    {
        Schema::table('states', function (Blueprint $table) {
            $table->dropColumn('abbreviation');
        });
    }
};
