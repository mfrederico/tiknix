<?php
/********************************************************/
/* List of functions really too odd to be anywhere else */
/********************************************************/

/**
 * CSRF token field helper - use in any form
 * Usage: <?= csrf_field() ?>
 */
function csrf_field(): string {
    return \app\SimpleCsrf::field();
}

/**
 * Get CSRF token value (for JavaScript/AJAX)
 * Usage: var token = '<?= csrf_token() ?>';
 */
function csrf_token(): string {
    return \app\SimpleCsrf::getToken();
}

/**
 * Is this tiknix running as the root control plane (the one that provisions
 * instances), rather than a provisioned sandbox instance?
 *
 * The AI Builder / instance tooling only makes sense on the control plane; a
 * sandbox is a leaf and must not spawn nested instances (until real host-aware
 * nesting exists). Detection keys off the running host vs the apex domain, so it
 * works without any per-instance config: a clone served at instance.tiknix.com
 * self-identifies as a sandbox, tiknix.com as the control plane.
 *
 * Apex defaults to "tiknix.com" (the slug.tiknix.com convention); override with
 * [app] control_plane_host in config. Fail-safe: an unknown host is treated as
 * the control plane so the root is never accidentally locked out of its tools.
 */
function is_control_plane(): bool {
    $root = strtolower(trim((string)(\Flight::get('app.control_plane_host') ?? '')));
    if ($root === '') $root = 'tiknix.com';
    $host = strtolower((string)(parse_url((string)\Flight::get('app.baseurl'), PHP_URL_HOST) ?: ''));
    if ($host === '') return true;   // unknown host -> never lock out the root
    return $host === $root;
}

/**
 * Should the "builder" tooling (AI Builder, Workbench, Agent Setup) be available
 * here? These belong on the root control plane, not inside a provisioned sandbox
 * instance (a leaf).
 *
 * Precedence: an explicit [app] builder_tools_enabled in config.ini wins — so a
 * provisioned sub-instance can hard-disable the tools regardless of host. If it
 * is unset, fall back to is_control_plane() host detection.
 */
function builder_tools_enabled(): bool {
    $v = \Flight::get('app.builder_tools_enabled');
    if ($v === null) return is_control_plane();   // unset -> auto-detect by host
    if (is_bool($v)) return $v;
    return in_array(strtolower(trim((string)$v)), ['1', 'true', 'on', 'yes', 'enabled'], true);
}

/**
 * HTML-escape helper (shorthand for htmlspecialchars). Used by scaffolded views.
 */
if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars(((string)$value) ?? '', ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Translate helper — FALLBACK only. When the Translatify package is installed it
 * files-autoloads its own t() first (dependency order), and this guard skips,
 * so the real locale-aware translator wins. Without the package, this passthrough
 * still interpolates :placeholders, so t()-wrapped code (scaffolded controllers,
 * views) works either way. Same signature as Translatify's t().
 */
if (!function_exists('t')) {
    function t(string $string, array $params = []): string {
        if (!$params) return $string;
        // Match Translatify semantics: vars are keyed WITHOUT the colon; a bare
        // alphanumeric key `name` replaces the `:name` placeholder. Non-alnum keys
        // are skipped (so we never eat a stray colon in the message).
        $repl = [];
        foreach ($params as $k => $v) {
            if (is_string($k) && preg_match('/^[A-Za-z0-9_]+$/', $k)) $repl[':' . $k] = (string)$v;
        }
        return $repl ? strtr($string, $repl) : $string;
    }
}

// Fallback autoloader for RedBeanPHP FUSE models (models/Model_X.php). Composer's
// classmap only knows models present at dump time, so a freshly scaffolded model
// wouldn't attach its hooks until `composer dump-autoload`. This picks it up at
// runtime instead — and stays per-instance (resolves relative to THIS app root),
// so it never writes into a shared/symlinked vendor classmap the way a dump would.
// Only fires for Model_* not already resolved by the classmap.
spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'Model_', 6) !== 0) return;
    $file = __DIR__ . '/../models/' . $class . '.php';
    if (is_file($file)) require $file;
});

function getOptionValue($opts,$key) {
	foreach($opts as $opt) {
		if ($opt->key == $key) return($opt);
	}
}

function getExt($filename) {
	$parts = explode('.',$filename);
	return($parts[count($parts)-1]);
}

function getBaseFile($filename) {
		$parts = explode('.',$filename);
		array_pop($parts);
		return(join('.',$parts));
}

function format_filesize($size)
{
    $sizes = Array('B', 'K', 'M', 'G', 'T', 'P', 'E');
    $ext = $sizes[0];
    for ($i=1; (($i < count($sizes)) && ($size >= 1024)); $i++)
    {
        $size = $size / 1024;
        $ext  = $sizes[$i];
    }
    return round($size, 2).$ext;
}

//...... Gives you an array of 'count' files, and 'size' space used
function getSpaceUsed($dir)
{
    $numFiles = 0;
    $spaceUsed = 0;
    if (is_dir($dir))
    {
        if ($dh = opendir($dir))
            while (($file = readdir($dh)) !== false)
                if (filetype($dir.$file) == 'file')
                {
                    $numFiles ++;
                    $spaceUsed += filesize($dir.$file);
                }
        closedir($dh);
    }
    return(array('count'=>$numFiles,'size'=>format_filesize($spaceUsed)));
}


function is_on($str)
{
	return(preg_match('/true|on|1|yes|enabled/i',$str));
}

function loadSqlData($sqlFile)
{
    //...... Loads the sql data file
    if ($fd = fopen($sqlFile,"r"))
    {
        while($Q = fgets($fd,2048))
        {
            if (preg_match("/^--/",trim($Q)))
                continue;
            else
                $qry[$x] .= $Q;
            if (preg_match("/;$/",trim($Q))) $x++;

        }

        //..... Go through each query and send them to mysql
        foreach($qry as $Q)
        {
            $Q=trim($Q);
            if (strlen($Q))
            {
                mysql_query($Q);
            }

            if (mysql_error())
            {
                return(-1);
            }
        }
        return (count($qry) > 0) ? 1 : 0;
    }
    else return(0);
}
function print_spew($str,$str2 = null){
	print_pre($str,$str2);
	die();	
}

function print_pre($str,$str2 = null)
{
	$trace = debug_backtrace();
	$caller = $trace[1];
	if (!isset($caller['class'])) $caller['class'] = 'anon';
	print "<b>{$caller['class']}->{$caller['function']}</b><br />";

	if (is_array($str) && is_array($str2))
	{
		print "<table><thead>";
		print "<th>Var 1</th>";
		print "<th>Var 2</th></thead>";
		print "<tdata><tr>";
		print "<td valign=\"top\"><pre>\n".print_r($str,true)."</pre></td>";
		print "<td valign=\"top\"><pre>\n".print_r($str2,true)."</pre></td>";
		print "</table>";
	}
	else
		print "<pre>".print_r($str,true)."</pre>";
}


function var_name(&$var, $scope=false, $prefix='unique', $suffix='value')
{
    if($scope) $vals = $scope;
    else      $vals = $GLOBALS;
    $old = $var;
    $var = $new = $prefix.rand().$suffix;
    $vname = FALSE;
    foreach($vals as $key => $val) {
      if($val === $new) $vname = $key;
    }
    $var = $old;
    return $vname;
}

function is_admin_ip($ip_addr,$admin_ip_list)
{
	return(in_array($ip_addr,$admin_ip_list));
}


function is_email($email){
return(preg_match("/^[-_.[:alnum:]]+@((([[:alnum:]]|[[:alnum:]][[:alnum:]-]*[[:alnum:]])\.)+(ad|ae|aero|af|ag|ai|al|am|an|ao|aq|ar|arpa|as|at|au|aw|az|ba|bb|bd|be|bf|bg|bh|bi|biz|bj|bm|bn|bo|br|bs|bt|bv|bw|by|bz|ca|cc|cd|cf|cg|ch|ci|ck|cl|cm|cn|co|com|coop|cr|cs|cu|cv|cx|cy|cz|de|dj|dk|dm|do|dz|ec|edu|ee|eg|eh|er|es|et|eu|fi|fj|fk|fm|fo|fr|ga|gb|gd|ge|gf|gh|gi|gl|gm|gn|gov|gp|gq|gr|gs|gt|gu|gw|gy|hk|hm|hn|hr|ht|hu|id|ie|il|in|info|int|io|iq|ir|is|it|jm|jo|jp|ke|kg|kh|ki|km|kn|kp|kr|kw|ky|kz|la|lb|lc|li|lk|lr|ls|lt|lu|lv|ly|ma|mc|md|mg|mh|mil|mk|ml|mm|mn|mo|mp|mq|mr|ms|mt|mu|museum|mv|mw|mx|my|mz|na|name|nc|ne|net|nf|ng|ni|nl|no|np|nr|nt|nu|nz|om|org|pa|pe|pf|pg|ph|pk|pl|pm|pn|pr|pro|ps|pt|pw|py|qa|re|ro|ru|rw|sa|sb|sc|sd|se|sg|sh|si|sj|sk|sl|sm|sn|so|sr|st|su|sv|sy|sz|tc|td|tf|tg|th|tj|tk|tm|tn|to|tp|tr|tt|tv|tw|tz|ua|ug|uk|um|us|uy|uz|va|vc|ve|vg|vi|vn|vu|wf|ws|ye|yt|yu|za|zm|zw)|(([0-9][0-9]?|[0-1][0-9][0-9]|[2][0-4][0-9]|[2][5][0-5])\.){3}([0-9][0-9]?|[0-1][0-9][0-9]|[2][0-4][0-9]|[2][5][0-5]))$/i",$email));
}


function genPass($size)
{
    $pass = '';
    $alpha = array('A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z',1,2,3,4,5,6,7,8,9,0);
    for ($x = 0;$x < $size;$x++)
    {
        $pass .= $alpha[rand(0,count($alpha)-1)];
    }
    return($pass);
}

/**
 * Pass in what you have and what you want
 * get back converted value or zero if it fails
 * digit precision forced
 *  To convert grams to pounds and ounces, 
 * first divide the gram value by 453.59237 to convert into pounds
 */
function weightConverter($val, $frmtype, $totype='lbs'){
    $rtn = 0;
    switch(strtolower($frmtype)){
        case 'grams':
            if($totype == 'lbs') $rtn = ($val*.002);
            break;
        case 'oz':
            if($totype == 'lbs') $rtn = ($val/16);
            break;
        case 'lbs':
        case 'pounds':
            if($totype == 'grams') $rtn = ($val*453.59237);
            if($totype == 'oz') $rtn = ($val/16);
			if($totype == 'lbs') $rtn = $val;
            break;
        default:
            break;
    }
    //echo print_r( ['converted'=>$rtn,'original'=>$val,'frmtype'=>$frmtype,'totype'=>$totype],true);
    return (empty($rtn))? round($val,6) : round($rtn,6);
}


/*
 * Only returns a value if a match was found
 * $val = value to map
 * $getKey = looking of two digit code by fullname 
 *          or fullname by two digit code 
 */
function mapCountry($val, $getKey=true){
 
    $country = ["AF"=>"Afghanistan","AX"=>"Åland Islands","AL"=>"Albania","DZ"=>"Algeria","AS"=>"American Samoa",
    "AD"=>"Andorra","AO"=>"Angola","AI"=>"Anguilla","AQ"=>"Antarctica","AG"=>"Antigua and Barbuda","AR"=>"Argentina",
    "AM"=>"Armenia","AW"=>"Aruba","AU"=>"Australia","AT"=>"Austria","AZ"=>"Azerbaijan","BS"=>"Bahamas","BH"=>"Bahrain",
    "BD"=>"Bangladesh","BB"=>"Barbados","BY"=>"Belarus","BE"=>"Belgium","BZ"=>"Belize","BJ"=>"Benin","BM"=>"Bermuda",
    "BT"=>"Bhutan","BO"=>"Bolivia","BA"=>"Bosnia and Herzegovina","BW"=>"Botswana","BV"=>"Bouvet Island","BR"=>"Brazil",
    "IO"=>"British Indian Ocean Territory","BN"=>"Brunei Darussalam","BG"=>"Bulgaria","BF"=>"Burkina Faso","BI"=>"Burundi",
    "KH"=>"Cambodia","CM"=>"Cameroon","CA"=>"Canada","CV"=>"Cape Verde","KY"=>"Cayman Islands","CF"=>"Central African Republic",
    "TD"=>"Chad","CL"=>"Chile","CN"=>"China","CX"=>"Christmas Island","CC"=>"Cocos (Keeling) Islands","CO"=>"Colombia",
    "KM"=>"Comoros","CG"=>"Congo","CD"=>"Congo, The Democratic Republic of The","CK"=>"Cook Islands","CR"=>"Costa Rica",
    "CI"=>"Cote D'ivoire","HR"=>"Croatia","CU"=>"Cuba","CY"=>"Cyprus","CZ"=>"Czechia","DK"=>"Denmark","DJ"=>"Djibouti",
    "DM"=>"Dominica","DO"=>"Dominican Republic","EC"=>"Ecuador","EG"=>"Egypt","SV"=>"El Salvador","GQ"=>"Equatorial Guinea",
    "ER"=>"Eritrea","EE"=>"Estonia","ET"=>"Ethiopia","FK"=>"Falkland Islands (Malvinas)","FO"=>"Faroe Islands","FJ"=>"Fiji",
    "FI"=>"Finland","FR"=>"France","GF"=>"French Guiana","PF"=>"French Polynesia","TF"=>"French Southern Territories",
    "GA"=>"Gabon","GM"=>"Gambia","GE"=>"Georgia","DE"=>"Germany","GH"=>"Ghana","GI"=>"Gibraltar","GR"=>"Greece",
    "GL"=>"Greenland","GD"=>"Grenada","GP"=>"Guadeloupe","GU"=>"Guam","GT"=>"Guatemala","GG"=>"Guernsey","GN"=>"Guinea",
    "GW"=>"Guinea-bissau","GY"=>"Guyana","HT"=>"Haiti","HM"=>"Heard Island and Mcdonald Islands","VA"=>"Holy See (Vatican City State)",
    "HN"=>"Honduras","HK"=>"Hong Kong","HU"=>"Hungary","IS"=>"Iceland","IN"=>"India","ID"=>"Indonesia","IR"=>"Iran, Islamic Republic of",
    "IQ"=>"Iraq","IE"=>"Ireland","IM"=>"Isle of Man","IL"=>"Israel","IT"=>"Italy","JM"=>"Jamaica","JP"=>"Japan",
    "JE"=>"Jersey","JO"=>"Jordan","KZ"=>"Kazakhstan","KE"=>"Kenya","KI"=>"Kiribati","KP"=>"Korea, Democratic People's Republic of",
    "KR"=>"Korea, Republic of","KW"=>"Kuwait","KG"=>"Kyrgyzstan","LA"=>"Lao People's Democratic Republic","LV"=>"Latvia",
    "LB"=>"Lebanon","LS"=>"Lesotho","LR"=>"Liberia","LY"=>"Libyan Arab Jamahiriya","LI"=>"Liechtenstein","LT"=>"Lithuania",
    "LU"=>"Luxembourg","MO"=>"Macao","MK"=>"Macedonia, The Former Yugoslav Republic of","MG"=>"Madagascar","MW"=>"Malawi",
    "MY"=>"Malaysia","MV"=>"Maldives","ML"=>"Mali","MT"=>"Malta","MH"=>"Marshall Islands","MQ"=>"Martinique","MR"=>"Mauritania",
    "MU"=>"Mauritius","YT"=>"Mayotte","MX"=>"Mexico","FM"=>"Micronesia, Federated States of","MD"=>"Moldova, Republic of",
    "MC"=>"Monaco","MN"=>"Mongolia","ME"=>"Montenegro","MS"=>"Montserrat","MA"=>"Morocco","MZ"=>"Mozambique","MM"=>"Myanmar",
    "NA"=>"Namibia","NR"=>"Nauru","NP"=>"Nepal","NL"=>"Netherlands","AN"=>"Netherlands Antilles","NC"=>"New Caledonia","NZ"=>"New Zealand",
    "NI"=>"Nicaragua","NE"=>"Niger","NG"=>"Nigeria","NU"=>"Niue","NF"=>"Norfolk Island","MP"=>"Northern Mariana Islands",
    "NO"=>"Norway","OM"=>"Oman","PK"=>"Pakistan","PW"=>"Palau","PS"=>"Palestinian Territory, Occupied","PA"=>"Panama",
    "PG"=>"Papua New Guinea","PY"=>"Paraguay","PE"=>"Peru","PH"=>"Philippines","PN"=>"Pitcairn","PL"=>"Poland","PT"=>"Portugal",
    "PR"=>"Puerto Rico","QA"=>"Qatar","RE"=>"Reunion","RO"=>"Romania","RU"=>"Russian Federation","RW"=>"Rwanda",
    "SH"=>"Saint Helena","KN"=>"Saint Kitts and Nevis","LC"=>"Saint Lucia","PM"=>"Saint Pierre and Miquelon","VC"=>"Saint Vincent and The Grenadines",
    "WS"=>"Samoa","SM"=>"San Marino","ST"=>"Sao Tome and Principe","SA"=>"Saudi Arabia","SN"=>"Senegal","RS"=>"Serbia",
    "SC"=>"Seychelles","SL"=>"Sierra Leone","SG"=>"Singapore","SK"=>"Slovakia","SI"=>"Slovenia","SB"=>"Solomon Islands",
    "SO"=>"Somalia","ZA"=>"South Africa","GS"=>"South Georgia and The South Sandwich Islands","ES"=>"Spain","LK"=>"Sri Lanka",
    "SD"=>"Sudan","SR"=>"Suriname","SJ"=>"Svalbard and Jan Mayen","SZ"=>"Swaziland","SE"=>"Sweden","CH"=>"Switzerland",
    "SY"=>"Syrian Arab Republic","TW"=>"Taiwan, Province of China","TJ"=>"Tajikistan","TZ"=>"Tanzania, United Republic of",
    "TH"=>"Thailand","TL"=>"Timor-leste","TG"=>"Togo","TK"=>"Tokelau","TO"=>"Tonga","TT"=>"Trinidad and Tobago","TN"=>"Tunisia",
    "TR"=>"Turkey","TM"=>"Turkmenistan","TC"=>"Turks and Caicos Islands","TV"=>"Tuvalu","UG"=>"Uganda","UA"=>"Ukraine","AE"=>"United Arab Emirates",
    "GB"=>"United Kingdom","US"=>"United States","UM"=>"United States Minor Outlying Islands","UY"=>"Uruguay","UZ"=>"Uzbekistan",
    "VU"=>"Vanuatu","VN"=>"Viet Nam","VG"=>"Virgin Islands, British","VI"=>"Virgin Islands, U.S.","WF"=>"Wallis and Futuna",
    "EH"=>"Western Sahara","YE"=>"Yemen","ZM"=>"Zambia","ZW"=>"Zimbabwe"];

	try { 
		$rtn = ($getKey)? array_search($val, $country) : $country[$val];
		if(!$rtn && $getKey && array_key_exists($val, $country)){ $rtn = $val; }
		return $rtn;
	}
	catch (Exception $e) {
	}
	return 'US';
}

if (!function_exists('parse_db_dsn')) {
    /**
     * Parse a URL-style database DSN into RedBeanPHP's R::setup() form, so a
     * single DB_DSN env var can drive the connection (e.g. on Hyperlift),
     * falling back to conf/config.ini when unset. Supported schemes:
     *
     *   mysql://user:pass@host:3306/dbname       (also mariadb://)
     *   pgsql://user:pass@host:5432/dbname       (also postgres://, postgresql://)
     *   sqlite:relative/path.db                  (relative to $projectRoot)
     *   sqlite:///absolute/path.db
     *   file://./relative/path.db                (alias for sqlite)
     *
     * The DB_PASSWORD env var, when set, overrides any password in the URL — so
     * the credential can stay out of the DSN string (and out of anything that
     * logs it).
     *
     * @param string $url         The DB_DSN value.
     * @param string $projectRoot Absolute root for resolving relative sqlite paths.
     * @return array{0:string,1:?string,2:?string} [pdoDsn, user|null, pass|null];
     *         user/pass are null for sqlite (R::setup called without credentials).
     * @throws \RuntimeException on an unparseable or unsupported DSN.
     */
    function parse_db_dsn(string $url, string $projectRoot = ''): array {
        $colon = strpos($url, ':');
        if ($colon === false) {
            throw new \RuntimeException("Invalid DB_DSN (no scheme): {$url}");
        }
        $scheme = strtolower(substr($url, 0, $colon));

        // sqlite / file -> a plain filesystem path, no credentials. Parsed by
        // hand (not parse_url, which returns false on sqlite:///abs forms).
        if ($scheme === 'sqlite' || $scheme === 'file') {
            $path = substr($url, $colon + 1);
            if (str_starts_with($path, '//')) {
                $path = substr($path, 2);   // drop the // authority marker
            }
            if ($path === '') {
                throw new \RuntimeException("DB_DSN sqlite path is empty: {$url}");
            }
            if ($path[0] !== '/' && $projectRoot !== '') {
                $path = rtrim($projectRoot, '/') . '/' . ltrim($path, './');
            }
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            return ["sqlite:{$path}", null, null];
        }

        // mysql / pgsql -> host-based, with credentials (parse_url is fine here).
        $p = parse_url($url);
        if ($p === false) {
            throw new \RuntimeException("Invalid DB_DSN (cannot parse): {$url}");
        }
        $driver = match ($scheme) {
            'mysql', 'mariadb'                => 'mysql',
            'pgsql', 'postgres', 'postgresql' => 'pgsql',
            default => throw new \RuntimeException("Unsupported DB_DSN scheme '{$scheme}': {$url}"),
        };
        $host = $p['host'] ?? 'localhost';
        $port = $p['port'] ?? ($driver === 'pgsql' ? 5432 : 3306);
        $db   = ltrim($p['path'] ?? '', '/');
        if ($db === '') {
            throw new \RuntimeException("DB_DSN missing database name: {$url}");
        }
        $user = isset($p['user']) ? rawurldecode($p['user']) : '';
        $pass = isset($p['pass']) ? rawurldecode($p['pass']) : '';

        // DB_PASSWORD overrides any inline password.
        $envPass = getenv('DB_PASSWORD');
        if ($envPass !== false) {
            $pass = $envPass;
        }

        $dsn = "{$driver}:host={$host};port={$port};dbname={$db}";
        if ($driver === 'mysql') {
            $dsn .= ';charset=utf8mb4';
        }
        return [$dsn, $user, $pass];
    }
}


?>
