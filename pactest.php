<html>
  <head>
    <title>PAC File Tester</title>
  </head>
  <link rel="stylesheet" href="styles.css">
<body>

<?php
// If form is submitted (POST)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
echo "<a href=\"javascript:history.back()\">Go Back to Form</a><BR>";
  $error = array();
  // Assign form values to friendly variables
  $URL = (!empty($_POST['url']) ? htmlspecialchars($_POST['url']) : "https://www.google.com"); // URL to test
  $HOST = (!empty($_POST['host']) ? htmlspecialchars($_POST['host']) : "www.google.com"); // Host part of URL
  $IP = (!empty($_POST['ip']) ? htmlspecialchars($_POST['ip']) : "192.168.1.100"); // Client IP used by myIpAddress() function -- defaults to 192.168.1.100 if none is provided.
  $NETWORK = htmlspecialchars($_POST['network']); // Forcepoint Network ID -- only used for Forcecpoint Cloud/Hybrid hosted PAC files.
  $PAC_SRC = htmlspecialchars($_POST['pac_source']); // PAC file text or URL for PAC file
  $PAC_TYPE = $_POST['pac_type']; // If PAC Source is a URL or direct TEXT

  // Check provided URL and verify it matches standard URL syntax http(s), ftp(s) or ws(s)
  if (!empty($URL) && !preg_match("/(?:^https?|ftps?|wss?):\/\/.*/i", $URL)) {
    echo "URL Provided is not valid.  Go back and fix.";
    exit();
  }

  // If PAC type is URL, download PAC file and store in pac_text variable and perform other checks
  if ($PAC_TYPE == "url") {
    // Verify that a valid http/s URL was provided, or display message and exit
    if (!empty($PAC_SRC) && !preg_match("/^https?:\/\/.*/i", htmlspecialchars_decode($PAC_SRC))) {
       echo "Invalid URL provided, did you input text? Go back and fix.";
       exit();
    }
    // If valid URL provided, attempt curl download:
    $curl_handle = curl_init();
    curl_setopt_array($curl_handle, array( CURLOPT_URL => htmlspecialchars_decode($PAC_SRC),
                                           CURLOPT_RETURNTRANSFER => true,
                                           CURLOPT_FOLLOWLOCATION => true));
    $pac_text = htmlspecialchars(curl_exec($curl_handle)); // retrieved PAC File text
    $http_resp = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE); // HTTP Response code received from curl request
    $curl_err_msg = curl_error($curl_handle); // Error message provided from curl if error occurred (Does not include HTTP errors)
    $curl_err_no = curl_errno($curl_handle); //Error number provided by curl in case of curl error.
    $curl_url = curl_getinfo($curl_handle, CURLINFO_EFFECTIVE_URL); // Effective (last) URL used by curl -- if original URL was redirected, this will be the redirected URL.

    // If curl returned an error then provide details and exit.
    if ($curl_err_no) {
      echo "CURL ERROR: An error was encountered by curl and PAC file could not be retrieved.<BR>";
      echo "PAC URL Provided: $PAC_SRC<BR>";
      echo "Message: $curl_err_msg";
      exit();
    }

    // If HTTP resposne from PAC URL did not end up wtih a 200 (initial 302 is OK as long as the final result is 200) the provide error details and exit.
    if ($http_resp != "200") {
      echo "CURL Error: Unable to retrieve PAC file from provided URL<BR>";
      echo "URL Provided: $PAC_SRC<BR>";
      echo "HTTP Response Code: $http_resp<BR>";
      echo "Response Received:<BR>$pac_text<BR>";
      exit();
    }

    // If a network ID was provided, replace the left side of the filtered location tests.
    // This allows simulating a dynamic PAC file downloading from a specific filtered location.
    if (!empty($NETWORK)) {
      // Test that a Network ID is likely valid -- does not confirm if ID is valid or not, just checks that it only consists of 1 to 10 digits.
      if (!preg_match("/[0-9]{1,10}/", $NETWORK)) {
        echo "Invalid Network ID provided. Go back and fix or leave blank.";
        exit();
      }
      $NETWORK = "Network_" . $NETWORK;  // Concatenate Network_ with the ID provided -- this is the syntax used by Forcepoint
      $pac_text = htmlspecialchars(preg_replace("/(Network_[0-9]+)?(' == 'Network_[0-9]+')/","$NETWORK$2",htmlspecialchars_decode($pac_text))); // Final PAC result is stored in pac_text
    }
  } else {
    // If PAC source is text, assign it to variable.
    $pac_text = $PAC_SRC;
  }

  // Build the pactester command:
  $cmd = "pactester -p -"; // -p - tells pactester to read the PAC contents from stdin which is provided as pipes[0] when executing the command later.
  $cmd_opts = array(); // Create empty array of options to use.

  // If client IP was provided, need to add the '-c' option for pactester
  // Client IP now defaults to 192.168.1.100 even if one is not provided, so this will always be true.
  if (!empty($IP)) {
    // Check if provided IP is valid.  must be 4 octects separated  by periods, each octet being 0-255.
    if (!preg_match("/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/", $IP)) {
      echo "Invalid Client IP address provided. Go back and fix or leave blank.";
      exit();
    }
    // set the -c option.
    $cmd_opts[] = "-c $IP";
  }

  $cmd_opts[] = "-u '$URL'"; // Adds the -u (URL) option with the provided URL.
  $cmd_opts[] = "-h '$HOST'"; // Adds the -h (host) option with the provided HOST

  // Concatenate all required and optional options with the pactester command
  foreach ($cmd_opts as $opt) {
    $cmd = $cmd . " " . $opt;
  }
  // build the command to execute by PHP.  decriptors are 0 = stdin, 1 = stdout, and 2 = stderr.
  // $pipes is used in php to reference each of the descriptors.
  $cmd_descriptors = array(0 => array("pipe", "r"), 1 => array("pipe", "w"), 2 => array("pipe", "w"));
  $process = proc_open($cmd, $cmd_descriptors, $pipes);
  if (is_resource($process)) {
    fwrite($pipes[0], htmlspecialchars_decode($pac_text)); // executes $process and passes $pac_text as stdin.
    fclose($pipes[0]); // close the stdin pipe
    $output = stream_get_contents($pipes[1]); // get stdout and store as $output
    $error = stream_get_contents($pipes[2]); // get stderr and store as $error.
    fclose($pipes[1]); // close stdout pipe
    fclose($pipes[2]); // close stderr pipe
    proc_close($process); // close process

    // if error, then display error and exit.
    if (!empty($error)) {
      echo "Error executing pactester: $error";
      exit();
    }
  }
  // display final output in formatted table
?>
<table>
  <tr>
    <td style="border: 1px solid #000000; font-weight: bold; text-align: right;">Command:</td>
    <td style="border: 1px solid #000000;"><?php echo $cmd; ?></td>
  </tr>
  <tr>
    <td style="border: 1px solid #000000; font-weight: bold; text-align: right;">Forcepoint Network ID:</td>
    <td style="border: 1px solid #000000"><?php echo (!empty($NETWORK)) ? $NETWORK : "N/A"; ?></td>
  </tr>
  <tr>
    <td style="border: 1px solid #000000; font-weight: bold; text-align: right;">Client IP:</td>
    <td style="border: 1px solid #000000"><?php echo (!empty($IP)) ? $IP : "N/A"; ?></td>
  </tr>
  <tr>
    <td style="border: 1px solid #000000; font-weight: bold; text-align: right;">URL:</td>
    <td style="border: 1px solid #000000"><?php echo $URL; ?></td>
  </tr>
  <tr>
    <td style="border: 1px solid #000000; font-weight: bold; text-align: right;">Host:</td>
    <td style="border: 1px solid #000000"><?php echo $HOST; ?></td>
  </tr>
  <tr>
    <td style="border: 1px solid #000000; font-weight: bold; text-align: right;">PAC URL:</td>
    <td style="border: 1px solid #000000"><?php echo ($PAC_TYPE == "url") ? $curl_url : "N/A"; ?></td>
  </tr>
  <tr>
    <td style="border: 1px solid #000000; font-weight: bold; text-align: right;">Proxy Result:</td>
    <td style="border: 1px solid #000000; font-weight: bold; font-size: 20px;"><B><?php echo $output; ?></B></td>
  </tr>
</table>
<BR>
<B>Pac File Contents:</B>
<P>
<PRE> <?php echo $pac_text; ?></PRE>
<?php
} else {
?>
<!-- Display form -->
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
  <table>
    <tr>
      <td style="border: 1px solid #000000; font-weight: bold;" align="right"><label for="network">Forcepoint Network ID</label><div class="tooltip">(?)<span class="tooltiptext">Optional<P>Description: This is the internally assigned Network ID for a particular filtered location (IP address/range) used by Forcepoint Cloud/Hybrid Web Filtering hostred PAC files.  The network ID is expressed as Network_1234567.</span></div>:</td>
      <td style="border: 1px solid #000000"> Network_<input type="text" name="network" placeholder="1234567" pattern="^[0-9]{1,10}$"></td>
    </tr>
    <tr>
      <td style="border: 1px solid #000000; font-weight: bold;" align="right"><label for="ip">Client IP<div class="tooltip">(?)<span class="tooltiptext">Optional.<P>Description: Used to populate the myIpAddress() function inside the PAC file.<P> Note: Has no function if myIpAddress() is not used.</span></div>:</td>
      <td style="border: 1px solid #000000"><input type="text" name="ip" placeholder="192.168.1.100" pattern="^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$"></td>
    </tr>
    <tr>
      <td style="border: 1px solid #000000; font-weight: bold;" align="right"><label for="url">URL<div class="tooltip"><font color="red"><sup>*</sup></font>(?)<span class="tooltiptext">Required<P>Description: Full URL passed, as 'url' parameter, to FindProxyForURL function.<P>Must include protocol: http(s), ftp(s), ws(s).</span></div>:</td>
      <td style="border: 1px solid #000000"><input style="width: 350px;" type="url" value="https://www.example.com" pattern="^([Hh][Tt][Tt][Pp][Ss]?|[Ff][Tt][Pp][Ss]?|[Ww][Ss][Ss]?):\/\/.*$" name="url" placeholder="https://www.example.com" required></td>
    </tr>
    <tr>
      <td style="border: 1px solid #000000; font-weight: bold;" align="right"><label for="host">Host<div class="tooltip"><font color="red"><sup>*</sup></font>(?)<span class="tooltiptext">Required<P>Description: Hostname/domain part of the URL, passed as 'host' parameter to FindProxyForURL function.<P>If the URL were http://www.google.com/search, then the host would be www.google.com.</span></div>:</td>
      <td style="border: 1px solid #000000"><input style="width: 350px;" type="text" value="www.example.com" name="host" placeholder="www.example.com" required></td>
    </tr>
    <tr>
      <td style="border: 1px solid #000000; font-weight: bold; text-align: right; vertical-align: top;">PAC File<div class="tooltip"><font color="red"><sup>*</sup></font>(?)<span class="tooltiptext">Required<P>Description: Select if you are supplying a PAC file URL (http/s allowed) or the full text of a PAC file.<P>If supplying a URL, should be in the format http://domain.com/proxy.pac.<P>If supply PAC file text, it should be include the full FindProxyForURL() function definition:<P>function FindProxyForURL(url, host)<BR>&#123;<BR>&nbsp;&nbsp;&nbsp;//PAC Logic Here<BR>&#125;</span></pre></div>:</td>
      <td style="border: 1px solid #000000">
        <input type="radio" name="pac_type" value="url">URL</input><input type="radio" name="pac_type" value="text" checked>Text</input><BR>
        Text/URL:<BR>
        <textarea style="width: 700px; height: 400px;" name="pac_source" placeholder="Insert URL to PAC file, or paste PAC file contents here..." required></textarea><BR>
        <input type="submit" value="submit" name="submit">
      </td>
    </tr>
  </table>
</form>
<!-- End form display -->

<?php
}
?>
</body>
</html>
