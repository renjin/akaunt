<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use App\Services\ChartOfAccountsTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'renjin21@gmail.com'],
            ['name' => 'Ren Jin', 'password' => Hash::make('password')], // change after first login
        );

        // The three entities. Names/details are editable in-app; legal_form drives the CoA template.
        $companies = [
            ['name' => 'O2O Alliance Sdn Bhd', 'slug' => 'o2o-alliance', 'legal_form' => 'sdn_bhd'],
            ['name' => 'Pet Grooming Co',      'slug' => 'pet-grooming', 'legal_form' => 'partnership'],
            ['name' => 'AgriTech Ops',         'slug' => 'agritech',     'legal_form' => 'sole_prop'],
        ];

        foreach ($companies as $data) {
            $company = Company::firstOrCreate(['slug' => $data['slug']], $data);
            $company->users()->syncWithoutDetaching($user);
            ChartOfAccountsTemplate::seed($company);
        }
    }
}
