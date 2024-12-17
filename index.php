<?php
header('Content-Type: application/json; charset=utf-8');


// Database connection settings
// note: read from .htaccess env
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
        	-- extract the date claimed returned
        	regexp_match(
	  			-- e.g. 'Fri Oct 15 2024: Claimed returned on Fri Oct 18 2024 by .p1180809',
        		v.field_content,
	  			'([A-Za-z]{3} [A-Za-z]{3} \d{2} \d{4})'
			)
		)[1]::DATE as claimed_date,
		substring(
			--e.g. 'Wed Jul 10 2024: Claimed returned on Wed Jun 26 2024 by .p2074239' 
			v.field_content
			FROM 'by \.?([^\s]+)'
		) AS patron_number
    FROM 
        sierra_view.item_record ir 
        JOIN sierra_view.record_metadata rm ON rm.id = ir.record_id  
        JOIN sierra_view.varfield v ON (
            v.record_id = ir.record_id 
            AND v.varfield_type_code = 'x'  -- note field
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
ORDER BY
    branch_name,
   	location_name,
   	call_number,
   	best_author
SQL;

// Execute the query
$result = pg_query($dbconn, $query);

if (!$result) {
    echo json_encode(["error" => "An error occurred while executing the query."]);
    exit;
}

// Fetch all results as an associative array
$data = pg_fetch_all($result);

if ($data) {
    // Convert the array to JSON
    echo json_encode($data, JSON_PRETTY_PRINT);
} else {
    echo json_encode(["message" => "No records found."]);
}

// Close the database connection
pg_close($dbconn);
?>
