<?php
header('Content-Type: text/html; charset=utf-8');

$page_title = "CHPL ILS Reports - Claims Returned 90";

echo <<<HTML
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>$page_title</title>

    <style>
        /* Styles for printing */
        @media print {
            body {
                font-size: 0.6rem !important; /* Force smaller text size globally */
            }
            
            table {
                width: 100%; /* Ensures tables span the full width when printed */
                font-size: 1rem !important; /* Force smaller text inside tables */
                page-break-inside: avoid !important; /* Prevent table from being split */
            }

            tr, td {
                page-break-inside: avoid !important; /* Prevent rows and cells from breaking */
                page-break-after: auto !important;  /* Allow normal row flow */
            }
            
            thead {
                display: table-header-group !important; /* Repeat the table header on each page */
            }
    
            tbody {
                display: table-row-group;
            }
            
            pre {
                font-size: 0.65rem !important; /* Force smaller text inside <pre> */
                /* line-height: 0.8 !important; Adjust line spacing */
            }
            
            h2 {
                font-size: 0.8rem !important; /* Shrink headers for print */
            }

            button {
                display: none !important; /* Ensure buttons are hidden */
            }

            .hidden {
                display: none !important;
            }
        }

        pre {
            font-family: 'Courier New', Courier, monospace;
            background-color: #f9f9f9;
            padding: 16px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            color: #333;
            /* line-height: 1.2; */
            font-size: 1rem;
            white-space: pre-wrap;
        }

        pre span.label {
            color: #888;
            font-weight: bold;
            margin-right: 8px;
        }

        pre span.value {
            color: #333;
        }

        pre span.line {
            display: block;
            margin-bottom: 4px;
        }

        .hidden {
            display: none;
        }

        table {
            width: 100%; /* Ensures the table spans the full width of the container */
            border-collapse: collapse; /* Ensures borders are merged for a cleaner look */
            /* margin-bottom: 1rem; Adds spacing between tables */
        }

        td, th {
            /* border: 1px solid #ddd; Adds a border to table cells */
            padding: 8px; /* Adds spacing inside cells */
            text-align: left; /* Aligns text to the left for readability */
        }

        th {
            background-color: #f2f2f2; /* Optional: Light gray background for table headers */
        }
    </style>
    <script>
        function makeTablePrintable(tableId) {
            // Hide all other content except the table with the given ID
            const allContent = document.body.children;
            const printableTable = document.getElementById(tableId);

            Array.from(allContent).forEach(el => {
                if (el !== printableTable) {
                    el.classList.add('hidden');
                }
            });

            // Trigger print
            window.print();

            // Restore visibility after printing
            setTimeout(() => {
                Array.from(allContent).forEach(el => el.classList.remove('hidden'));
            }, 500);
        }
    </script>
</head>

<body>
    <h2>$page_title</h2>

    <h3>Claims Returned Instructions:</h3>
    <p>This is a cumulative list of items marked as <strong>Claims Returned</strong> in the past 90 days.</p>

    <ol>
        <li>
            <strong>Search for items:</strong>
            <ul>
                <li>Check the normal shelving locations.</li>
                <li>Check other sections where the item might have been shelved incorrectly (e.g., an adult non-fiction item might be in the juvenile non-fiction section).</li>
            </ul>
        </li>
        <li>
            <strong>If an item is found:</strong>
            <ul>
                <li>Check it in so it appears as available in Sierra, the Classic Catalog, and Encore.</li>
            </ul>
        </li>
        <li>
            <strong>If an item is not found:</strong>
            <ul>
                <li>Take no action.</li>
                <li><strong>Do not edit the item status</strong> (e.g., do not mark as missing or withdrawn).</li>
            </ul>
        </li>
    </ol>

    <h2>Important</h2>
    <ul>
        <li>Editing the item status breaks the Claims Returned link.</li>
        <li>This prevents the removal of related notes on the item and user records if the item is later checked in.</li>
        <li><strong>The user remains responsible for the item.</strong></li>
    </ul>

    <div id="top"></div>
HTML;

// Database connection settings
$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$dbname = getenv('DB_NAME');
$user = getenv('DB_USER');
$password = getenv('DB_PASSWORD');

// Connecting to PostgreSQL
$connectionString = "host=$host port=$port dbname=$dbname user=$user password=$password";
$dbconn = pg_connect($connectionString);

if (!$dbconn) {
    echo "Error: Unable to open database\n";
    exit;
}

// Query to run
$query = <<<SQL
WITH item_data AS (
    SELECT 
        rm.record_num AS item_record_num,
        ir.record_id AS item_record_id,
        ir.location_code,
        ln2."name" as location_name,
        bn."name" as branch_name,
        rm.campus_code,
        rm.record_last_updated_gmt::date AS record_last_update, 
        UPPER(irp.call_number_norm) AS call_number,
        UPPER((
        	select 
        	v.field_content
        	from 
        	sierra_view.varfield as v
        	where 
        	v.record_id = vrirl.volume_record_id
        	order by v.occ_num
        	limit 1
        )) as vol_stmnt,
        brp.best_title,
		brp.best_author,
		brp.publish_year,
        irp.barcode,
        v.field_content,
        (
        	regexp_match(
        		v.field_content,
	  			'([A-Za-z]{3} [A-Za-z]{3} \d{2} \d{4})'
			)
		)[1]::DATE as claimed_date,
		substring(
			v.field_content
			FROM 'by \.?([^\s]+)'
		) AS patron_number
    FROM 
        sierra_view.item_record ir 
        JOIN sierra_view.record_metadata rm ON rm.id = ir.record_id  
        JOIN sierra_view.varfield v ON (
            v.record_id = ir.record_id 
            AND v.varfield_type_code = 'x'  
            AND v.field_content ~* 'claimed returned'
        )
        left outer join sierra_view.bib_record_item_record_link brirl on brirl.item_record_id = ir.record_id
        left outer join sierra_view.bib_record_property brp on brp.bib_record_id = brirl.bib_record_id
        left outer join sierra_view.item_record_property irp ON irp.item_record_id = ir.record_id
        left outer join sierra_view.volume_record_item_record_link as vrirl on vrirl.item_record_id = ir.record_id
        left outer join sierra_view."location" as l on l.code = ir.location_code
        left outer join sierra_view.location_name ln2 on ln2.location_id = l.id
        left outer join sierra_view.branch as b on b.code_num = l.branch_code_num
        left outer join sierra_view.branch_name as bn on bn.branch_id = b.id 
    WHERE 
        ir.item_status_code = 'z'
)
SELECT 
    item_record_num,
	item_record_id,
	location_code,
	location_name,
	branch_name,
	campus_code,
	record_last_update,
	call_number,
	vol_stmnt,
	best_title,
	best_author,
	publish_year,
	barcode,
	field_content,
	claimed_date,
	patron_number,
	(claimed_date < CURRENT_DATE - INTERVAL '90 days') AS is_over_ninety_days
FROM 
    item_data
WHERE
    (claimed_date < CURRENT_DATE - INTERVAL '90 days') is FALSE
ORDER BY
    branch_name,
   	-- location_name,
    location_code,
   	call_number,
   	best_author
SQL;

// Execute the query
$result = pg_query($dbconn, $query);

if (!$result) {
    echo "<p>An error occurred while executing the query.</p>";
    exit;
}

$data = pg_fetch_all($result);

if ($data) {
    $branches = [];
    foreach ($data as $row) {
        $branches[$row['branch_name']][] = $row;
    }

    echo "<div><strong>Jump to Branch:</strong><br>";
    foreach ($branches as $branchName => $items) {
        $branchId = urlencode($branchName);
        echo "<a href='#" . $branchId . "'>" . htmlspecialchars($branchName) . "</a> | ";
    }
    echo "</div>";

    foreach ($branches as $branchName => $items) {
        $branchId = urlencode($branchName);
        $tableId = "table_" . $branchId;
        echo "<h2 id='" . $branchId . "'>" . htmlspecialchars($branchName) . "</h2>";
        echo "<button onclick=\"makeTablePrintable('$tableId')\">Print This Branch List</button>";
        echo "<table id='" . $tableId . "'>";

        foreach ($items as $item) {
            // $author = !empty($item['best_author']) ? htmlspecialchars($item['best_author']) . " / " : "";

            echo "<tr><td><pre>";
            echo "Item Record #  : " . htmlspecialchars($item['item_record_num']) . "\n";
            echo "Location       : " . htmlspecialchars($item['location_name']) . "\n";
            echo "Call # / Vol   : " . htmlspecialchars($item['call_number']) . " " . htmlspecialchars($item['vol_stmnt']) . "\n"; 
            if  (!empty($item['best_author'])) {
                echo "Author         : " . htmlspecialchars($item['best_author']) . "\n";
            }
            echo "Title          : " . htmlspecialchars($item['best_title']) . "\n";
            echo "Barcode        : " . htmlspecialchars($item['barcode']) . "\n";
            echo "</pre></td></tr>";
        }

        echo "</table>";
        echo "<p><a href='#top'>Back to Top</a></p><br>";
    }
    
    echo "</body></html>";
} else {
    echo "<p>No records found.</p>";
}

pg_close($dbconn);
?>
