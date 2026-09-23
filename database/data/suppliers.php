<?php

declare(strict_types=1);

// Fictional suppliers seeded for the demo (SupplierSeeder). Real ones live in the suppliers table.
return [
    ['id' => 'sup-01', 'name' => 'Northgate Interiors', 'email' => 'tenders@northgate.example', 'trades' => ['partitions', 'ceilings'], 'regions' => ['london', 'south-east'], 'rating' => 4.6],
    ['id' => 'sup-02', 'name' => 'Stud & Board Ltd', 'email' => 'estimating@studboard.example', 'trades' => ['partitions'], 'regions' => ['london', 'midlands'], 'rating' => 4.4],
    ['id' => 'sup-03', 'name' => 'Grid Ceilings Co', 'email' => 'quotes@gridceilings.example', 'trades' => ['ceilings'], 'regions' => ['london', 'south-east', 'midlands'], 'rating' => 4.7],
    ['id' => 'sup-04', 'name' => 'Fairway Flooring', 'email' => 'bids@fairway.example', 'trades' => ['flooring'], 'regions' => ['london', 'south-east'], 'rating' => 4.8],
    ['id' => 'sup-05', 'name' => 'Loom & Tile', 'email' => 'rfq@loomtile.example', 'trades' => ['flooring', 'decoration'], 'regions' => ['london', 'north-west'], 'rating' => 4.3],
    ['id' => 'sup-06', 'name' => 'Brightline Electrical', 'email' => 'estimating@brightline.example', 'trades' => ['electrical'], 'regions' => ['london', 'south-east', 'midlands'], 'rating' => 4.5],
    ['id' => 'sup-07', 'name' => 'Airflow Building Services', 'email' => 'tenders@airflow.example', 'trades' => ['mechanical', 'plumbing'], 'regions' => ['london', 'south-east'], 'rating' => 4.6],
    ['id' => 'sup-08', 'name' => 'Oakbench Joinery', 'email' => 'quotes@oakbench.example', 'trades' => ['joinery', 'doors'], 'regions' => ['london', 'south-east', 'midlands'], 'rating' => 4.9],
    ['id' => 'sup-09', 'name' => 'Portal Doorsets', 'email' => 'sales@portal.example', 'trades' => ['doors'], 'regions' => ['london', 'north-west', 'scotland'], 'rating' => 4.2],
    ['id' => 'sup-10', 'name' => 'Firewall Protection Services', 'email' => 'bids@firewall.example', 'trades' => ['fire_protection'], 'regions' => ['london', 'south-east', 'midlands', 'north-west'], 'rating' => 4.4],
    ['id' => 'sup-11', 'name' => 'Clean Coat Decorators', 'email' => 'estimating@cleancoat.example', 'trades' => ['decoration'], 'regions' => ['london', 'south-east'], 'rating' => 4.1],
    ['id' => 'sup-12', 'name' => 'Pipeworks Mechanical', 'email' => 'tenders@pipeworks.example', 'trades' => ['plumbing', 'mechanical'], 'regions' => ['london', 'midlands'], 'rating' => 4.3],
];
