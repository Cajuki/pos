<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Business
        $business = Business::firstOrCreate(
            ['name' => 'Demo Retail Ltd'],
            ['currency' => 'KES', 'timezone' => 'Africa/Nairobi']
        );

        // 2. Development Users
        $defaultPassword = Hash::make('poss-demo-2026');

        $owner = User::firstOrCreate(
            ['email' => 'admin@demo.test'],
            ['name' => 'Alex Morgan', 'password' => $defaultPassword]
        );
        if (! $owner->businesses()->where('businesses.id', $business->id)->exists()) {
            $owner->businesses()->attach($business, ['role' => 'owner']);
        }

        $cashier = User::firstOrCreate(
            ['email' => 'cashier@demo.test'],
            ['name' => 'Faith Chebet', 'password' => $defaultPassword]
        );
        if (! $cashier->businesses()->where('businesses.id', $business->id)->exists()) {
            $cashier->businesses()->attach($business, ['role' => 'cashier']);
        }

        $manager = User::firstOrCreate(
            ['email' => 'manager@demo.test'],
            ['name' => 'David Mutua', 'password' => $defaultPassword]
        );
        if (! $manager->businesses()->where('businesses.id', $business->id)->exists()) {
            $manager->businesses()->attach($business, ['role' => 'manager']);
        }

        // 3. Warehouses
        $mainWarehouse = Warehouse::firstOrCreate(
            ['business_id' => $business->id, 'code' => 'WH-MAIN'],
            ['name' => 'Nairobi Central Warehouse', 'address' => 'Industrial Area, Commercial St', 'is_default' => true, 'is_active' => true]
        );
        $westlandsBranch = Warehouse::firstOrCreate(
            ['business_id' => $business->id, 'code' => 'WH-WEST'],
            ['name' => 'Westlands Retail Outlet', 'address' => 'Mpaka Road, Westlands', 'is_default' => false, 'is_active' => true]
        );

        // 4. Categories
        $categoriesData = [
            ['name' => 'Beverages', 'description' => 'Teas, coffees, juices, sodas, and water'],
            ['name' => 'Grocery & Grains', 'description' => 'Flours, rice, pulses, cooking fats, sugar'],
            ['name' => 'Snacks & Confectionery', 'description' => 'Biscuits, crisps, chocolates, and nuts'],
            ['name' => 'Personal Care & Hygiene', 'description' => 'Soaps, lotions, dental care, shampoos'],
            ['name' => 'Household & Cleaning', 'description' => 'Detergents, disinfectants, paper products'],
        ];

        $categories = [];
        foreach ($categoriesData as $cData) {
            $categories[$cData['name']] = Category::firstOrCreate(
                ['business_id' => $business->id, 'name' => $cData['name']],
                ['description' => $cData['description'], 'is_active' => true]
            );
        }

        // 5. 50 Realistic Kenyan Products
        $catalog = [
            // Beverages
            ['Kericho Gold Black Tea 250g', 'TEA-KG-250', '61611000101', 'Beverages', '220.00', '165.00', 10, 85],
            ['Ketepa Pride Tea Bags 100s', 'TEA-KP-100', '61611000102', 'Beverages', '280.00', '210.00', 8, 45],
            ['Java House House Blend 375g', 'COF-JH-375', '61611000103', 'Beverages', '780.00', '590.00', 5, 28],
            ['Nescafe Classic 100g Jar', 'COF-NES-100', '61611000104', 'Beverages', '460.00', '350.00', 6, 34],
            ['Brookside Fresh Milk 500ml', 'MLK-BK-500', '61611000105', 'Beverages', '65.00', '52.00', 20, 110],
            ['KCC Gold Crown Long Life 500ml', 'MLK-KCC-500', '61611000106', 'Beverages', '70.00', '56.00', 15, 75],
            ['Milo Drink Powder 400g Tin', 'COCO-MILO-400', '61611000107', 'Beverages', '520.00', '395.00', 8, 40],
            ['Coca Cola Original 500ml PET', 'SOD-CC-500', '61611000108', 'Beverages', '70.00', '50.00', 25, 96],
            ['Fanta Orange 500ml PET', 'SOD-FO-500', '61611000109', 'Beverages', '70.00', '50.00', 20, 64],
            ['Keringet Mineral Water 1L', 'WTR-KG-1L', '61611000110', 'Beverages', '95.00', '68.00', 15, 80],
            ['Del Monte Pineapple Juice 1L', 'JUC-DM-1L', '61611000111', 'Beverages', '240.00', '185.00', 10, 42],

            // Grocery & Grains
            ['Unga wa Dola Maize Meal 2kg', 'MZ-DOLA-2KG', '61611000201', 'Grocery & Grains', '165.00', '135.00', 25, 140],
            ['Jogoo Maize Meal 2kg', 'MZ-JOG-2KG', '61611000202', 'Grocery & Grains', '170.00', '138.00', 20, 120],
            ['Hostess Premium Maize Flour 2kg', 'MZ-HOST-2KG', '61611000203', 'Grocery & Grains', '230.00', '180.00', 10, 35],
            ['Ajab All Purpose Wheat Flour 2kg', 'WHT-AJAB-2KG', '61611000204', 'Grocery & Grains', '185.00', '150.00', 15, 90],
            ['Exe Mandazi Flour 2kg', 'WHT-EXE-2KG', '61611000205', 'Grocery & Grains', '195.00', '158.00', 12, 50],
            ['Daawat Basmati Rice 2kg', 'RIC-DAW-2KG', '61611000206', 'Grocery & Grains', '540.00', '430.00', 10, 60],
            ['Pishori Mwea Pure Rice 2kg', 'RIC-PIS-2KG', '61611000207', 'Grocery & Grains', '420.00', '335.00', 12, 70],
            ['Rina Vegetable Cooking Oil 2L', 'OIL-RINA-2L', '61611000208', 'Grocery & Grains', '580.00', '480.00', 15, 55],
            ['Elianto Pure Corn Oil 1L', 'OIL-ELI-1L', '61611000209', 'Grocery & Grains', '410.00', '330.00', 8, 38],
            ['Fresh Fri Cooking Oil 3L', 'OIL-FF-3L', '61611000210', 'Grocery & Grains', '870.00', '720.00', 10, 42],
            ['Kabras Pure White Sugar 1kg', 'SGR-KAB-1KG', '61611000211', 'Grocery & Grains', '155.00', '125.00', 20, 115],
            ['Kabras Pure White Sugar 2kg', 'SGR-KAB-2KG', '61611000212', 'Grocery & Grains', '295.00', '240.00', 15, 80],
            ['Royco Beef Flavour Cubes 40s', 'SP-ROY-CUB', '61611000213', 'Grocery & Grains', '120.00', '88.00', 15, 95],
            ['Kensalt Iodised Table Salt 1kg', 'SLT-KEN-1KG', '61611000214', 'Grocery & Grains', '45.00', '32.00', 25, 150],

            // Snacks & Confectionery
            ['Manji Digestive Biscuits 400g', 'BSC-DIG-400', '61611000301', 'Snacks & Confectionery', '175.00', '130.00', 10, 65],
            ['Nuvita Glucose Biscuits 100g', 'BSC-NUV-100', '61611000302', 'Snacks & Confectionery', '45.00', '30.00', 20, 110],
            ['Cadbury Dairy Milk 80g', 'CHK-CAD-80', '61611000303', 'Snacks & Confectionery', '190.00', '145.00', 12, 48],
            ['Krust Potato Crisps Salted 50g', 'SNK-KRS-50', '61611000304', 'Snacks & Confectionery', '65.00', '45.00', 15, 80],
            ['Urban Bites Chilli Lemon 100g', 'SNK-UB-100', '61611000305', 'Snacks & Confectionery', '135.00', '98.00', 12, 54],
            ['Tropical Sweets Mint 200g', 'SWT-TRP-200', '61611000306', 'Snacks & Confectionery', '110.00', '78.00', 10, 72],
            ['Kenya Nut Out of Africa Macadamia 100g', 'NUT-MAC-100', '61611000307', 'Snacks & Confectionery', '320.00', '240.00', 6, 25],
            ['Stoney Tangawizi Can 330ml', 'SOD-STY-330', '61611000308', 'Snacks & Confectionery', '80.00', '58.00', 15, 60],

            // Personal Care & Hygiene
            ['Geisha Bath Soap Aloe Vera 225g', 'SOP-GEI-225', '61611000401', 'Personal Care & Hygiene', '140.00', '105.00', 15, 90],
            ['Dettol Original Soap 175g', 'SOP-DET-175', '61611000402', 'Personal Care & Hygiene', '175.00', '132.00', 12, 60],
            ['Colgate Herbal Toothpaste 140g', 'DEN-COL-140', '61611000403', 'Personal Care & Hygiene', '210.00', '155.00', 15, 70],
            ['Sensodyne Rapid Relief 75ml', 'DEN-SEN-75', '61611000404', 'Personal Care & Hygiene', '490.00', '380.00', 5, 24],
            ['Nivea Rich Nourishing Body Lotion 400ml', 'LOT-NIV-400', '61611000405', 'Personal Care & Hygiene', '680.00', '520.00', 6, 32],
            ['Vaseline Pure Petroleum Jelly 250ml', 'LOT-VAS-250', '61611000406', 'Personal Care & Hygiene', '285.00', '215.00', 10, 58],
            ['Nice & Lovely Body Oil 200ml', 'LOT-NL-200', '61611000407', 'Personal Care & Hygiene', '230.00', '170.00', 8, 44],
            ['Rexona Men Cobalt Antiperspirant 150ml', 'DEO-REX-150', '61611000408', 'Personal Care & Hygiene', '390.00', '290.00', 6, 28],
            ['Always Ultra Thin Sanitary Pads 8s', 'HYG-ALW-8', '61611000409', 'Personal Care & Hygiene', '115.00', '82.00', 20, 105],

            // Household & Cleaning
            ['Omo Fast Action Detergent Powder 1kg', 'CLN-OMO-1KG', '61611000501', 'Household & Cleaning', '345.00', '265.00', 12, 65],
            ['Ariel Complete Detergent 1kg', 'CLN-ARL-1KG', '61611000502', 'Household & Cleaning', '360.00', '280.00', 10, 50],
            ['Sunlight 2in1 Washing Powder 1kg', 'CLN-SUN-1KG', '61611000503', 'Household & Cleaning', '285.00', '218.00', 15, 75],
            ['Menengai Bar Soap White 800g', 'SOP-MEN-800', '61611000504', 'Household & Cleaning', '175.00', '135.00', 20, 95],
            ['Vim Scouring Powder Lemon 500g', 'CLN-VIM-500', '61611000505', 'Household & Cleaning', '140.00', '102.00', 10, 48],
            ['Harpic Power Plus Bleach 750ml', 'CLN-HAR-750', '61611000506', 'Household & Cleaning', '320.00', '245.00', 8, 42],
            ['Jik Regular Bleach 750ml', 'CLN-JIK-750', '61611000507', 'Household & Cleaning', '225.00', '170.00', 12, 56],
            ['Fay White Toilet Tissue 10 Pack', 'PAP-FAY-10', '61611000508', 'Household & Cleaning', '420.00', '320.00', 10, 60],
        ];

        $createdProducts = [];
        foreach ($catalog as $item) {
            [$name, $sku, $barcode, $categoryName, $price, $cost, $reorder, $initialStock] = $item;

            $product = Product::firstOrCreate(
                ['business_id' => $business->id, 'sku' => $sku],
                [
                    'name' => $name,
                    'category' => $categoryName,
                    'category_id' => $categories[$categoryName]->id ?? null,
                    'barcode' => $barcode,
                    'unit_price' => $price,
                    'cost_price' => $cost,
                    'reorder_level' => $reorder,
                    'is_active' => true,
                ]
            );

            $stock = InventoryStock::firstOrCreate(
                ['business_id' => $business->id, 'product_id' => $product->id],
                ['quantity_on_hand' => $initialStock]
            );

            // Create initial stock movement if not exists
            if (! StockMovement::where('business_id', $business->id)->where('product_id', $product->id)->exists()) {
                StockMovement::create([
                    'business_id' => $business->id,
                    'inventory_stock_id' => $stock->id,
                    'product_id' => $product->id,
                    'type' => 'opening_balance',
                    'quantity_change' => $initialStock,
                    'quantity_before' => 0,
                    'quantity_after' => $initialStock,
                    'reason' => 'Initial stock setup for Demo Retail Ltd',
                ]);
            }

            $createdProducts[] = $product;
        }

        // 6. 20 Customers
        $customersData = [
            ['Wangari Maathai', '0722100201', 'wangari@retail.ke', 'Upper Hill, Nairobi'],
            ['Juma Omondi', '0733200302', 'juma.omondi@corp.ke', 'Kilimani, Nairobi'],
            ['Wanjiku Kamau', '0711300403', 'wanjiku.k@gmail.com', 'Westlands, Nairobi'],
            ['Hassan Kiprop', '0724400504', 'hassan.kip@outlook.com', 'Eldoret Plaza'],
            ['Achieng Onyango', '0715500605', 'achieng.o@safari.ke', 'Kisumu Central'],
            ['Mutua Musyoka', '0726600706', 'mutua.musyoka@yahoo.com', 'Machakos Town'],
            ['Njeri Mwangi', '0737700807', 'njeri.m@gmail.com', 'Runda Estate, Nairobi'],
            ['Brian Koech', '0718800908', 'brian.koech@fin.co.ke', 'Nakuru Highway'],
            ['Fatuma Ali', '0729900019', 'fatuma.ali@coast.ke', 'Mombasa CBD'],
            ['Kevin Wanyama', '0721010203', 'kevin.w@techhub.ke', 'Lavington, Nairobi'],
            ['Esther Chepkemoi', '0732120304', 'esther.c@gmail.com', 'Kileleshwa, Nairobi'],
            ['Samuel Gitau', '0713230405', 'samuel.gitau@kenya.net', 'Thika Road, Roysambu'],
            ['Zainab Swaleh', '0724340506', 'zainab@malindi.ke', 'Parklands, Nairobi'],
            ['Denis Njoroge', '0735450607', 'denis.n@craft.ke', 'Ngong Road, Karen'],
            ['Mercy Akinyi', '0716560708', 'mercy.a@gmail.com', 'South B, Nairobi'],
            ['Victor Cheruiyot', '0727670809', 'victor.c@agro.ke', 'Kericho Green Mall'],
            ['Beatrice Wambui', '0738780910', 'beatrice.w@gmail.com', 'Kiambu Town'],
            ['Peter Kiprono', '0719891011', 'peter.k@rift.ke', 'Naivasha Town'],
            ['Grace Muthoni', '0720901112', 'grace.m@gmail.com', 'Kahawa Sukari, Nairobi'],
            ['Titus Makau', '0731011213', 'titus.makau@gmail.com', 'Kitengela Junction'],
        ];

        foreach ($customersData as [$cName, $cPhone, $cEmail, $cAddr]) {
            Customer::firstOrCreate(
                ['business_id' => $business->id, 'phone' => $cPhone],
                ['name' => $cName, 'email' => $cEmail, 'address' => $cAddr, 'status' => 'active']
            );
        }

        // 7. 10 Suppliers
        $suppliersData = [
            ['Brookside Dairy Ltd', '0720001001', 'orders@brookside.co.ke', 'Ruiru Industrial Park'],
            ['Bidco Africa Ltd', '0720001002', 'sales@bidco-africa.com', 'Thika Industrial Hub'],
            ['Unga Group Plc', '0720001003', 'commercial@unga.com', 'Commercial Street, Nairobi'],
            ['Kapa Oil Refineries', '0720001004', 'distribution@kapa-oil.com', 'Mombasa Road, Nairobi'],
            ['East African Breweries Ltd', '0720001005', 'orders@eabl.com', 'Ruaraka, Thika Road'],
            ['Broadway Bakery Ltd', '0720001006', 'sales@broadway.co.ke', 'Thika Industrial Estate'],
            ['Unilever Kenya Ltd', '0720001007', 'retail.ke@unilever.com', 'Commercial St, Industrial Area'],
            ['Colgate-Palmolive EA', '0720001008', 'kenya.sales@colpal.com', 'Enterprise Road, Nairobi'],
            ['Kensalt Salt Manufacturers', '0720001009', 'orders@kensalt.com', 'Mombasa Port Rd'],
            ['Pwani Oil Products', '0720001010', 'sales@pwani.com', 'Jomvu Kuu, Mombasa'],
        ];

        $suppliers = [];
        foreach ($suppliersData as [$sName, $sPhone, $sEmail, $sAddr]) {
            $suppliers[] = Supplier::firstOrCreate(
                ['business_id' => $business->id, 'name' => $sName],
                ['phone' => $sPhone, 'email' => $sEmail, 'address' => $sAddr, 'status' => 'active']
            );
        }

        // 8. Cash Register & Session
        $register = CashRegister::firstOrCreate(
            ['business_id' => $business->id, 'code' => 'REG-01'],
            ['name' => 'Main Front Counter POS', 'is_active' => true]
        );

        $session = CashRegisterSession::firstOrCreate(
            ['business_id' => $business->id, 'cash_register_id' => $register->id, 'status' => 'open'],
            [
                'user_id' => $owner->id,
                'opening_balance' => 5000.00,
                'expected_cash' => 5000.00,
                'opened_at' => now()->startOfDay(),
            ]
        );

        // 9. Realistic Historical Sales (over the last 14 days)
        if (Sale::where('business_id', $business->id)->count() < 10) {
            $paymentOptions = ['cash', 'mpesa', 'card', 'credit'];

            for ($i = 1; $i <= 35; $i++) {
                $daysAgo = rand(0, 10);
                $saleTime = now()->subDays($daysAgo)->subHours(rand(1, 10))->subMinutes(rand(1, 55));
                $paymentMethod = $paymentOptions[array_rand($paymentOptions)];

                // Pick 1 to 4 random products
                $itemCount = rand(1, 4);
                $chosenProducts = collect($createdProducts)->random($itemCount);

                $subtotal = 0;
                $itemsData = [];

                foreach ($chosenProducts as $prod) {
                    $qty = rand(1, 3);
                    $lineTotal = (float) $prod->unit_price * $qty;
                    $subtotal += $lineTotal;

                    $itemsData[] = [
                        'product' => $prod,
                        'qty' => $qty,
                        'unit_price' => $prod->unit_price,
                        'cost_price' => $prod->cost_price,
                        'line_total' => $lineTotal,
                    ];
                }

                $receiptNum = 'POS-'.$saleTime->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));

                $sale = Sale::create([
                    'business_id' => $business->id,
                    'user_id' => $owner->id,
                    'receipt_number' => $receiptNum,
                    'payment_method' => $paymentMethod,
                    'status' => $paymentMethod === 'credit' ? 'on_credit' : 'paid',
                    'subtotal' => $subtotal,
                    'total' => $subtotal,
                    'created_at' => $saleTime,
                    'updated_at' => $saleTime,
                ]);

                foreach ($itemsData as $data) {
                    $sale->items()->create([
                        'product_id' => $data['product']->id,
                        'sku' => $data['product']->sku,
                        'product_name' => $data['product']->name,
                        'quantity' => $data['qty'],
                        'unit_price' => $data['unit_price'],
                        'unit_cost' => $data['cost_price'],
                        'line_total' => $data['line_total'],
                    ]);

                    // Decrement stock
                    $stock = InventoryStock::where('business_id', $business->id)
                        ->where('product_id', $data['product']->id)
                        ->first();

                    if ($stock && $stock->quantity_on_hand >= $data['qty']) {
                        $before = $stock->quantity_on_hand;
                        $stock->decrement('quantity_on_hand', $data['qty']);

                        StockMovement::create([
                            'business_id' => $business->id,
                            'inventory_stock_id' => $stock->id,
                            'product_id' => $data['product']->id,
                            'sale_id' => $sale->id,
                            'type' => 'sale',
                            'quantity_change' => -$data['qty'],
                            'quantity_before' => $before,
                            'quantity_after' => $stock->quantity_on_hand,
                            'reason' => "POS sale {$receiptNum}",
                            'created_at' => $saleTime,
                        ]);
                    }
                }
            }
        }

        // 10. Expense Categories & Sample Expenses
        $expenseCategories = [
            'Rent & Premises',
            'Utilities & Power',
            'Staff Wages & Lunch',
            'Transport & Logistics',
            'Store Maintenance',
        ];

        foreach ($expenseCategories as $catName) {
            $cat = ExpenseCategory::firstOrCreate(
                ['business_id' => $business->id, 'name' => $catName]
            );

            if ($catName === 'Rent & Premises' && ! Expense::where('business_id', $business->id)->where('description', 'Monthly Storefront Lease')->exists()) {
                Expense::create([
                    'business_id' => $business->id,
                    'expense_category_id' => $cat->id,
                    'created_by' => $owner->id,
                    'reference_number' => 'EXP-2026-RENT',
                    'description' => 'Monthly Storefront Lease',
                    'payment_method' => 'bank',
                    'amount' => 45000.00,
                    'incurred_on' => now()->startOfMonth(),
                ]);
            }

            if ($catName === 'Utilities & Power' && ! Expense::where('business_id', $business->id)->where('description', 'Kenya Power Token Bill')->exists()) {
                Expense::create([
                    'business_id' => $business->id,
                    'expense_category_id' => $cat->id,
                    'created_by' => $owner->id,
                    'reference_number' => 'EXP-2026-KPLC',
                    'description' => 'Kenya Power Token Bill',
                    'payment_method' => 'mpesa',
                    'amount' => 8400.00,
                    'incurred_on' => now()->subDays(4),
                ]);
            }
        }

        // 11. Sample Purchase Orders
        if (PurchaseOrder::where('business_id', $business->id)->count() === 0 && count($suppliers) > 0) {
            $supplier = $suppliers[0];
            $poRef = 'PO-'.now()->format('Ymd').'-001';
            $po = PurchaseOrder::create([
                'business_id' => $business->id,
                'supplier_id' => $supplier->id,
                'warehouse_id' => $mainWarehouse->id,
                'created_by' => $owner->id,
                'reference_number' => $poRef,
                'status' => 'received',
                'subtotal' => 24500.00,
                'total' => 24500.00,
                'ordered_at' => now()->subDays(5),
                'received_at' => now()->subDays(3),
                'notes' => 'Weekly Brookside milk replenishment',
            ]);

            $milkProd = Product::where('business_id', $business->id)->where('sku', 'MLK-BK-500')->first();
            if ($milkProd) {
                $po->items()->create([
                    'product_id' => $milkProd->id,
                    'sku' => $milkProd->sku,
                    'product_name' => $milkProd->name,
                    'quantity_ordered' => 200,
                    'quantity_received' => 200,
                    'unit_cost' => 52.00,
                    'tax_amount' => 0,
                    'line_total' => 10400.00,
                ]);
            }
        }
    }
}
