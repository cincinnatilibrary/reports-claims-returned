<?php
header('Content-Type: text/html; charset=utf-8');

/* ─────────────────────────────  CONFIG  ────────────────────────────── */
$page_title = "CHPL ILS Reports – Claims Returned (last 14 days)";

/* ──────────────────────  1. read request parameter  ────────────────── */
$branch_param = $_GET['branch'] ?? '';      // empty string = All branches

/* ─────────────────────────  2. connect to PG  ───────────────────────── */
$dsn = sprintf(
    "host=%s port=%s dbname=%s user=%s password=%s",
    getenv('DB_HOST'), getenv('DB_PORT'),
    getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASSWORD')
);
$db = pg_connect($dsn) or die("<p>Database connection failed.</p>");

/* ─────────────  3. list of branches (for the <select>)  ────────────── */
$branch_opts = [];
$res_branches = pg_query($db,
    "SELECT DISTINCT name FROM sierra_view.branch_name ORDER BY 1");
while ($row = pg_fetch_assoc($res_branches)) {
    $branch_opts[] = $row['name'];
}

/* ───────────────  4. main query (parameterised)  ───────────────────── */
$sql = <<<SQL
WITH item_data AS (
    SELECT
        rm.record_num                       AS item_record_num,
        ir.record_id                        AS item_record_id,
        ir.location_code,
        ln2."name"                          AS location_name,
        bn."name"                           AS branch_name,
        rm.campus_code,
        rm.record_last_updated_gmt::date    AS record_last_update,
        UPPER(irp.call_number_norm)         AS call_number,
        UPPER((
            SELECT v.field_content
            FROM   sierra_view.varfield v
            WHERE  v.record_id = vrirl.volume_record_id
            ORDER  BY v.occ_num
            LIMIT  1
        ))                                   AS vol_stmnt,
        brp.best_title,
        brp.best_author,
        brp.publish_year,
        irp.barcode,
        v.field_content,
        (regexp_match(
             v.field_content,
            '([A-Za-z]{3} [A-Za-z]{3} \\d{2} \\d{4})'
        ))[1]::date                          AS claimed_date,
        substring(v.field_content FROM 'by \\.?([^\\s]+)')
                                             AS patron_number
    FROM   sierra_view.item_record                       ir
    JOIN   sierra_view.record_metadata                   rm   ON rm.id = ir.record_id
    JOIN   sierra_view.varfield                          v    ON (
               v.record_id          = ir.record_id
           AND v.varfield_type_code = 'x'
           AND v.field_content      ~* 'claimed returned'
           )
    LEFT   JOIN sierra_view.bib_record_item_record_link  brirl ON brirl.item_record_id = ir.record_id
    LEFT   JOIN sierra_view.bib_record_property          brp   ON brp.bib_record_id    = brirl.bib_record_id
    LEFT   JOIN sierra_view.item_record_property         irp   ON irp.item_record_id   = ir.record_id
    LEFT   JOIN sierra_view.volume_record_item_record_link vrirl ON vrirl.item_record_id = ir.record_id
    LEFT   JOIN sierra_view."location"                   l     ON l.code              = ir.location_code
    LEFT   JOIN sierra_view.location_name                ln2   ON ln2.location_id     = l.id
    LEFT   JOIN sierra_view.branch                       b     ON b.code_num          = l.branch_code_num
    LEFT   JOIN sierra_view.branch_name                  bn    ON bn.branch_id        = b.id
    WHERE  ir.item_status_code = 'z'
)
SELECT *
FROM   item_data
WHERE  claimed_date >= CURRENT_DATE - INTERVAL '14 days'
  AND  ($1::text = '' OR branch_name ILIKE $1)
ORDER  BY branch_name,
         claimed_date,          -- ← oldest first; add DESC for newest first
         location_code,
         call_number,
         best_author;
SQL;

$res = pg_query_params($db, $sql, [$branch_param]);
if (!$res) die("<p>Query failed.</p>");
$rows = pg_fetch_all($res);

/* ─────────────────────────────  HTML  ──────────────────────────────── */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($page_title) ?></title>
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
function makeTablePrintable(tableId){
  const keep=document.getElementById(tableId);
  const others=[...document.body.children].filter(el=>el!==keep);
  others.forEach(el=>el.classList.add('hidden'));
  /* wait one tick so the .hidden style applies before print */
  setTimeout(()=>{
    window.print();
    others.forEach(el=>el.classList.remove('hidden'));
  },0);
}
</script>
</head>
<body>
<h2><?= htmlspecialchars($page_title) ?></h2>

<form method="get">
  <label>Choose a branch:
    <select name="branch" onchange="this.form.submit()">
      <option value="">All branches</option>
      <?php foreach ($branch_opts as $bn):
              $sel = ($bn === $branch_param) ? ' selected' : ''; ?>
        <option value="<?= htmlspecialchars($bn) ?>"<?= $sel ?>>
            <?= htmlspecialchars($bn) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <noscript><button type="submit">Go</button></noscript>
</form>

<p>This report lists items set to <strong>Claims Returned</strong> in the last
<strong>14 days</strong>. Click a branch’s print button to print only that branch.</p>

<?php
if (!$rows){
    echo "<p>No records found for the selected branch.</p></body></html>";
    exit;
}

/* group by branch */
$by_branch=[];
foreach($rows as $r) $by_branch[$r['branch_name']][]=$r;

/* jump links if multiple branches */
if (count($by_branch) > 1){
    echo "<p><strong>Jump to branch:</strong><br>";
    foreach($by_branch as $bn => $items){
        $id=urlencode($bn);
        echo "<a href='#$id'>".htmlspecialchars($bn)."</a> | ";
    }
    echo "</p>";
}
echo "<div id='top'></div>";

foreach($by_branch as $bn => $items){
    $id=urlencode($bn);
    $tbl_id="tbl_$id";
    echo "<h3 id='$id'>".htmlspecialchars($bn)."</h3>";
    echo "<button onclick=\"makeTablePrintable('$tbl_id')\">Print This Branch List</button>";
    echo "<table id='$tbl_id'>";
    foreach($items as $it){
        echo "<tr><td><pre>";
        echo "Item Record #  : ".htmlspecialchars($it['item_record_num'])."\n";
        echo "Location       : ".htmlspecialchars($it['location_name'])."\n";
        echo "Call #/Vol     : ".htmlspecialchars($it['call_number'])." ".htmlspecialchars($it['vol_stmnt'])."\n";
        if ($it['best_author'])
            echo "Author         : ".htmlspecialchars($it['best_author'])."\n";
        echo "Title          : ".htmlspecialchars($it['best_title'])."\n";
        echo "Barcode        : ".htmlspecialchars($it['barcode'])."\n";
        echo "Claimed Date   : ".htmlspecialchars($it['claimed_date'])."\n";
        echo "</pre></td></tr>";
    }
    echo "</table>";
    echo "<p><a href='#top'>Back to top</a></p><br>";
}
?>
</body>
</html>
<?php pg_close($db); ?>
