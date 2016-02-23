<?php

require_once 'EzmlmInterface.php';
// composer
require_once 'vendor/autoload.php';

/**
 * Library for ezmlm discussion lists management
 */
class Ezmlm implements EzmlmInterface {

	/** JSON config */
	protected $config = array();
	public static $CONFIG_PATH = "config/config.json";

	/** ezmlm-idx tools path */
	protected $ezmlmIdxPath;

	/** Authentication management */
	protected $authAdapter;

	/** absolute path of domains root (eg vpopmail's "domains" folder) */
	protected $domainsPath;

	/** current mailing list domain (eg "my-domain.org") */
	protected $domainName;

	/** absolute path of current domain @TODO rename not to confuse with $domainsPath ? */
	protected $domainPath;

	/** current mailing list (eg "my-list") */
	protected $listName;

	/** absolute path of current mailing list */
	protected $listPath;

	/** absolute path of cache folder */
	protected $cachePath;

	/** various settings read from config */
	protected $settings;

	/** grep binary - a recent version is needed */
	protected $grepBinary;

	/** "find" binary - might not be found by PHP's exec() shell */
	protected $findBinary;

	/** abbreviations used by ezmlm-archive */
	protected $monthsNumbers = array(
		"jan" => "01",
		"feb" => "02",
		"fev" => "02",
		"mar" => "03",
		"apr" => "04",
		"avr" => "04",
		"may" => "05",
		"mai" => "05",
		"jun" => "06",
		"jul" => "07",
		"aug" => "08",
		"aou" => "08",
		"sep" => "09",
		"oct" => "10",
		"nov" => "11",
		"dec" => "12"
	);

	public function __construct() {
		// config
		if (file_exists(self::$CONFIG_PATH)) {
			$this->config = json_decode(file_get_contents(self::$CONFIG_PATH), true);
		} else {
			throw new Exception("file " . self::$CONFIG_PATH . " doesn't exist");
		}

		// ezmlm-idx tools path configuration
		$this->ezmlmIdxPath = $this->config['ezmlm-idx']['binariesPath'];

		// authentication adapter / rights management
		$this->authAdapter = null;
		// rights management is not mandatory
		if (! empty($this->config['authAdapter'])) {
			$authAdapterName = $this->config['authAdapter'];
			$authAdapterDir = strtolower($authAdapterName);
			$authAdapterPath = 'auth/' . $authAdapterDir . '/' . $authAdapterName . '.php';
			if (strpos($authAdapterName, "..") != false || $authAdapterName == '' || ! file_exists($authAdapterPath)) {
				throw new Exception ("auth adapter " . $authAdapterPath . " doesn't exist");
			}
			require $authAdapterPath;
			// passing config to the adapter - 
			$this->authAdapter = new $authAdapterName($this->config);
		}

		// domains path
		$this->domainsPath = $this->config['ezmlm']['domainsPath'];
		// default domain
		$this->setDomain($this->config['ezmlm']['domain']);
		// cache path
		$this->cachePath = $this->config['cache']['path'];
		// various settings
		$this->settings = $this->config['settings'];

		// binaries location (specific versions might be needed)
		$this->grepBinary = "grep";
		if (!empty($this->config['system']['grepBinary'])) {
			$this->grepBinary = $this->config['system']['grepBinary'];
		}
		$this->findBinary = "find";
		if (!empty($this->config['system']['findBinary'])) {
			$this->findBinary = $this->config['system']['findBinary'];
		}
	}

	protected function notImplemented() {
		throw new Exception("Method not implemented yet");
	}

	// ------------------ SYSTEM METHODS --------------------------

	/**
	 * Ensures that a user-given command or argument to a command does not
	 * threaten security
	 * @WARNING minimalistic
	 * @TODO improve !
	 */
	protected function ckeckAllowedArgument($arg) {
		if (strpos($arg, "..") !== false || strpos($arg, "/") !== false) {
			throw new Exception("forbidden command / argument: [$arg]");
		}
	}

	/**
	 * Runs an ezmlm-idx binary, located in $this->ezmlmIdxPath
	 * Output parameters are optional to reduce memory consumption
	 */
	protected function runEzmlmTool($tool, $optionsString, &$stdout=false, &$stderr=false) {
		$this->checkAllowedArgument($tool);
		// prepare proces opening
		$descriptorspec = array(
			0 => array("pipe", "r"),
			1 => array("pipe", "w"),
			2 => array("pipe", "w")
		);  
		// cautiousness
		$cwd = '/tmp';
		$process = proc_open($this->ezmlmIdxPath . '/' . $tool . ' ' . $optionsString, $descriptorspec, $pipes, $cwd);

		if (is_resource($process)) {
			// optionally write something to stdin
			fclose($pipes[0]);
			if ($stdout !== false) {
				$stdout = stream_get_contents($pipes[1]);
			}
			fclose($pipes[1]);
			if ($stderr !== false) {
				$stderr = stream_get_contents($pipes[2]);
			}
			fclose($pipes[2]);
			// It is important that you close any pipes before calling
			// proc_close in order to avoid a deadlock
			$return_value = proc_close($process);

			return $return_value;
		} else {
			throw new Exception('rt(): cound not create process');
		}
	}

	/**
	 * Run ezmlm-idx tool; convenience method for runEzmlmTool()
	 * Throws an exception containing stderr if the command returns something
	 * else that 0; otherwise, returns true if $returnStdout is false (default)
	 * or returns stdout otherwise
	 */
	protected function rt($tool, $optionsString, $returnStdout=false) {
		$ret = false;
		$stdout = $returnStdout;
		$stderr = null;
		// "smart" call to reduce memory consumption
		$ret = $this->runEzmlmTool($tool, $optionsString, $stdout, $stderr);
		// catch command error
		if ($ret !== 0) {
			throw new Exception($stderr);
		}
		// mixed return
		if ($returnStdout) {
			return $stdout;
		}
		return true;
	}

	// ------------------ UTILITY METHODS -------------------------

	/**
	 * Sets current directory to the current domain directory
	 */
	protected function chdirToDomain() {
		if (! is_dir($this->domainPath)) {
			throw new Exception("domain path: cannot access directory [" . $this->domainPath . "]");
		}
		chdir($this->domainPath);
	}

	/**
	 * Returns true if $fileName exists in directory $dir, false otherwise
	 * @TODO replace by a simple file_exists() provided by PHP ?!
	 */
	protected function fileExistsInDir($fileName, $dir) {
		$dirP = opendir($dir);
		$exists = false;
		while (($file = readdir($dirP)) && ! $exists) {
			$exists = ($exists || ($file == $fileName));
		}
		return $exists;
	}

	/*
	 * Returns true if list $name exists in $this->domainPath domain
	 */
	protected function listExists($name) {
		return is_dir($this->domainPath . '/' . $name);
	}

	/**
	 * Throws an exception if $email is not valid, in the
	 * meaning of PHP's FILTER_VALIDATE_EMAIL
	 */
	protected function checkValidEmail($email) {
		if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new Exception("invalid email address [$email]");
		}
	}

	/**
	 * Throws an exception if $this->listName is not set
	 */
	protected function checkValidList() {
		if (empty($this->listName)) {
			throw new Exception("please set a valid list");
		}
		if (!is_dir($this->listPath)) {
			throw new Exception("list [" . $this->listName . "] does not exist");
		}
	}

	/**
	 * Throws an exception if $this->listName is not set
	 */
	protected function checkValidDomain() {
		if (empty($this->domainName)) {
			throw new Exception("please set a valid domain");
		}
		if (!is_dir($this->domainPath)) {
			throw new Exception("domain [" . $this->domainName . "] does not exist");
		}
	}

	/**
	 * Throws an exception if $this->cachePath is not set, or if the directory
	 * does not exist or is not writable
	 */
	protected function checkValidCache() {
		if (empty($this->cachePath)) {
			throw new Exception("please set a valid cache path");
		}
		if (!is_dir($this->cachePath)) {
			throw new Exception("cache folder [" . $this->cachePath . "] does not exist");
		}
		if (!is_writable($this->cachePath)) {
			throw new Exception("cache folder [" . $this->cachePath . "] is not writable");
		}
	}

	/**
	 * Converts a string of letters (ex: "aBuD") to a command line switches
	 * string (ex: "-a -B -u -D") @WARNING only supports single letter switches !
	 */
	protected function getSwitches($options) {
		$switches = '';
		if (!empty($options)) {
			$switchesArray = array();
			$optionsArray = str_split($options);
			// not twice the same switch
			$optionsArray = array_unique($optionsArray);
			foreach ($optionsArray as $opt) {
				// only letters are allowed
				if (preg_match('/[a-zA-Z]/', $opt)) {
					$switchesArray[] = '-' . $opt;
				}
			}
			$switches = implode(' ', $switchesArray);
		}
		return $switches;
	}

	/**
	 * Generates an author's hash using the included makehash program
	 * (i) copied from original lib
	 */
	protected function makehash($str) {
		$str = preg_replace ('/>/', '', $str); // wtf ?
		$hash = $this->rt("makehash", $str, true);
		return $hash;
	}

	/**
	 * Checks if a string is valid UTF-8; if not, converts it from $fallbackCharset
	 * to UTF-8; returns the UTFized string
	 */
	protected function utfize($str, $fallbackCharset='ISO-8859-1') {
		$result = $this->utfizeAndStats($str, $fallbackCharset);
		return $result[0];
	}

	/**
	 * Checks if a string is valid UTF-8; if not, converts it from $fallbackCharset
	 * to UTF-8; returns an array containing the UTFized string, and a boolean
	 * telling if a conversion was performed
	 */
	protected function utfizeAndStats($str, $fallbackCharset='ISO-8859-1') {
		$valid_utf8 = preg_match('//u', $str);
		if (! $valid_utf8) {
			$str = iconv($fallbackCharset, "UTF-8//TRANSLIT", $str);
		}
		return array($str, ($valid_utf8 !== false));
	}

	/**
	 * Comparison function for usort() returning threads having the greatest
	 * "last_message_id" first
	 */
	protected function sortMostRecentThreads($a, $b) {
		return $b['last_message_id'] - $a['last_message_id'];
	}

	/**
	 * Comparison function for usort() returning threads having the lowest
	 * "last_message_id" first
	 * @TODO use first_message_id instead ?
	 */
	protected function sortLeastRecentThreads($a, $b) {
		return $this->sortMostRecentThreads($b, $a);
	}

	/**
	 * Converts a "*" based pattern to a preg compatible regex pattern
	 */
	protected function convertPatternForPreg($pattern) {
		if ($pattern == "*") {
			$pattern = false; // micro-optimization
		}
		if ($pattern != false) {
			$pattern = str_replace('*', '.*', $pattern);
			$pattern = '/^' . $pattern . '$/is'; // case insensitive, multilines
		}
		return $pattern;
	}

	/**
	 * Converts a "*" based pattern to a grep compatible regex pattern
	 */
	protected function convertPatternForGrep($pattern) {
		$pattern = str_replace('*', '.*', $pattern);
		$pattern = '^' . $pattern . '$';
		return $pattern;
	}

	/**
	 * If $silent is true, returns a message stub to represent a message not
	 * found in the archive; if $silent is false, throws an Exception
	 */
	protected function messageNotFound($silent=false) {
		if ($silent) {
			return array(
				"message_id" => false,
				"subject_hash" => false,
				"subject" => false,
				"message_date" => false,
				"author_hash" => false,
				"author_name" => false,
				"author_email" => false
			);
		} else {
			throw new Exception("message not found");
		}
	}

	// ------------------ PARSING METHODS -------------------------

	/**
	 * Sets an option in ezmlm config so that the "Reply-To:" header points
	 * to the list address and not the sender's
	 * @TODO find a proper way to know if it worked or not
	 * � copyleft David Delon 2005 - Tela Botanica / Outils R�seaux
	 */
	protected function setReplyToHeader() {
		$this->checkValidDomain();
		$this->checkValidList();
		// files to be modified
		$headerRemovePath = $this->listPath . '/headerremove';
		$headerAddPath = $this->listPath . '/headeradd';
		// commands
		$hrCommand = "echo -e 'reply-to' >> " . $headerRemovePath;
		$haCommand = "echo -e 'Reply-To: <#l#>@<#h#>' >> " . $headerAddPath;
		//echo $haCommand; exit;
		exec($hrCommand);
		exec($haCommand);
	}

	protected function computeSubfolderAndId($id) {
		// ezmlm archive format : http://www.mathematik.uni-ulm.de/help/qmail/ezmlm.5.html
		$subfolder = intval($id / 100);
		$messageId = $id - (100 * $subfolder);
		if ($messageId < 10) {
			$messageId = '0' . $messageId;
		}
		return array($subfolder, $messageId);
	}

	protected function extractMessageMetadata($line1, $line2) {
		// Line 1 : get message number, subject hash and subject
		preg_match('/^([0-9]+): ([a-z]+)( .*)?$/', $line1, $match1);
		// Line 2 : get date, author hash and hash
		preg_match('/^\t([0-9]+ [a-zA-Z][a-zA-Z][a-zA-Z] [0-9][0-9][0-9][0-9] [^;]+)?;([^ ]*) (.*)$/', $line2, $match2);

		$message = null;
		if ($match1[1] != '') {
			//var_dump($match2);
			$timestamp = strtotime($match2[1]);
			$date = null;
			if ($timestamp != false) {
				$date = date('Y-m-d h:i:s', $timestamp);
			}
			$subject = "";
			if (isset($match1[3])) {
				$subject = $this->utfize(trim($match1[3]));
			}
			// formatted return
			$message = array(
				"message_id" => intval($match1[1]),
				"subject_hash" => $match1[2],
				"subject" => $subject,
				"message_date" => $date, // @TODO include time zone ?
				"author_hash" => $match2[2],
				"author_name" => $this->utfize($match2[3]),
				"author_email" => $this->readMessageAuthorEmail(intval($match1[1])) // doesn't seem to cost so much
			);
		}
		return $message;
	}

	/**
	 * Reads "num" file in list dir to get total messages count
	 */
	protected function countMessagesFromArchive() {
		$numFile = $this->listPath . '/num';
		if (! file_exists($numFile)) {
			throw new Exception('list has no num file');
		}
		$num = file_get_contents($numFile);
		$num = explode(':', $num);

		return intval($num[0]);
	}

	/**
	 * Reads $limit messages from the list archive. Beware: setting $limit to 0 means no limit.
	 * If $includeMessages is true, returns the parsed message contents along with the metadata;
	 * if $includeMessages is "abstract", returns only the first characters of the message.
	 */
	protected function readMessagesFromArchive($includeMessages=false, $limit=false, $sort='desc', $offset=0) {
		// check valid limit
		if (! is_numeric($limit) || $limit <= 0) {
			$limit = false;
		}
		// check valid sort order
		if ($sort != 'asc') {
			$sort = 'desc';
		}
		// check valid offset
		if (!is_numeric($offset) || $offset < 0) {
			$offset = 0;
		}
		// idiot-proof attempt
		if ($includeMessages === true) { // unlimited abstracts are allowed
			if (! empty($this->settings['maxMessagesWithContentsReadableAtOnce']) && ($this->settings['maxMessagesWithContentsReadableAtOnce']) < $limit) {
				throw new Exception("cannot read more than " . $this->settings['maxMessagesWithContentsReadableAtOnce'] . " messages at once, if messages contents are included");
			}
		}

		$archiveDir = $this->listPath . '/archive';
		if (! is_dir($archiveDir)) {
			throw new Exception('list is not archived'); // @TODO check if archive folder exists in case a list is archived but empty
		}
		$archiveD = opendir($archiveDir);
		//echo "Reading $archiveDir \n";
		// get all subfolders
		$subfolders = array();
		while (($d = readdir($archiveD)) !== false) {
			if (preg_match('/[0-9]+/', $d)) {
				$subfolders[] = $d;
			}
		}
		// sort and reverse order if needed (last messages first)
		sort($subfolders, SORT_NUMERIC);
		if ($sort == 'desc') {
			$subfolders = array_reverse($subfolders);
		}
		//var_dump($subfolders);

		$messages = array();
		// read index files for each folder
		$idx = 0;
		$read = 0;
		$length = count($subfolders);
		// number of index files to skip // @WARNING if sort=desc, needs to
		// know how many messages there are in the last index file (<= 100)
		/*$indexFilesToSkip = intval(floor(($offset + 1) / 100));
		$idx += $indexFilesToSkip;*/
		// go
		while (($idx < $length) && ($limit == false || $limit > $read)) { // stop if enough messages are read
			$sf = $subfolders[$idx];
			// @WARNING setting limit to 0 means unlimited
			$subMessages = $this->readMessagesFromArchiveSubfolder($sf, $includeMessages, $sort, ($limit - $read), $offset);
			$messages = array_merge($messages, $subMessages);
			$read += count($subMessages); // might be 0 if using an offset
			$idx++;
		}

		// bye
		closedir($archiveD);

		return $messages;
	}

	/**
	 * Returns all messages whose date matches the given $datePortion, using
	 * "YYYY[-MM[-DD]]" format (ie. "2015", "2015-04" or "2015-04-23")
	 */
	protected function readMessagesByDate($datePortion, $contents=false, $limit=false, $sort='desc', $offset=0) {
		// check valid limit
		if (! is_numeric($limit) || $limit <= 0) {
			$limit = false;
		}
		// check valid sort order
		if ($sort != 'asc') {
			$sort = 'desc';
		}
		// check valid offset
		if (!is_numeric($offset) || $offset < 0) {
			$offset = 0;
		}
		// idiot-proof attempt
		if ($contents === true) { // unlimited abstracts are allowed
			if (! empty($this->settings['maxMessagesWithContentsReadableAtOnce']) && ($this->settings['maxMessagesWithContentsReadableAtOnce']) < $limit) {
				throw new Exception("cannot read more than " . $this->settings['maxMessagesWithContentsReadableAtOnce'] . " messages at once, if messages contents are included");
			}
		}
		// date to search for
		$year = substr($datePortion, 0, 4);
		$month = substr($datePortion, 5, 2);
		$day = substr($datePortion, 8, 2);
		if (! is_numeric($year)) {
			throw new Exception("invalid date [$year]");
		}
		// file(s) to read
		$pattern = $year;
		$monthsNames = array_flip($this->monthsNumbers);
		if ($month != false) {
			$pattern = $monthsNames[$month] . ' ' . $pattern;
		}
		if ($day != false) {
			$pattern = '\t' . $day . ' ' . $pattern;
		}
		//var_dump($pattern); exit;
		$archiveDir = $this->listPath . '/archive';
		$command = $this->grepBinary . ' --no-group-separator -i -hP -B1 "' . $pattern . '" ' . $archiveDir . '/*/index';
		exec($command, $output);
		// sort (BASH "*" expansion is alphabetical)
		if ($sort == 'desc') {
			$output = array_reverse($output);
		}
		$count = floor(count($output) / 2);
		//echo "<pre>"; var_dump($output); echo "</pre>"; exit;

		$messages = $this->readMessagesPairsOfLines($output, $contents, $sort, $limit, $offset) ;
		$msgs = array();
		foreach ($messages as &$msg) {
			$msgs[] = $msg;
		}
		//echo "<pre>"; var_dump($msgs); echo "</pre>"; exit;

		return array(
			"total" => $count,
			"data" => $msgs
		);
	}

	/**
	 * Comparison function for usort() returning oldest messages first : a lower
	 * message_id always mean an older date
	 */
	protected function sortMessagesByIdAsc($a, $b) {
		return $a['message_id'] - $b['message_id'];
	}

	/**
	 * Comparison function for usort() returning oldest messages first : a lower
	 * message_id always mean an older date
	 */
	protected function sortMessagesByIdDesc($a, $b) {
		return $this->sortMessagesByIdAsc($b, $a);
	}

	/**
	 * Reads $limit messages from an archive subfolder (ex: archive/0, archive/1) - this represents maximum
	 * 100 messages - then returns all metadata for each message, along with the messages contents if
	 * $includeMessages is true; limits the output to $limit messages, if $limit is a valid number > 0;
	 * beware: setting $limit to 0 means no limit !
	 * $offset will be used to skip messages while > 0 and updated for each message skipped
	 */
	protected function readMessagesFromArchiveSubfolder($subfolder, $includeMessages=false, $sort='desc', $limit=false, &$offset=0) {
		// check valid limit
		if (! is_numeric($limit) || $limit <= 0) {
			$limit = false;
		}

		$index = file($this->listPath . '/archive/' . $subfolder . '/index');
		// if $sort='desc', read file backwards
		if ($sort == 'desc') {
			$index = array_reverse($index);
		}
		$messages = $this->readMessagesPairsOfLines($index, $includeMessages, $sort, $limit, $offset);

		return $messages;
	}

	/**
	 * Reads couples of lines from a message index file, to extract messages
	 */
	protected function readMessagesPairsOfLines($lines, $includeMessages=false, $sort='desc', $limit=false, &$offset=0) {
		// read 2 lines at once - @WARNING considers number of lines in file is always even !
		$length = count($lines);
		$idx = 0;
		$read = 0;
		$messages = array();
		while ($idx < $length && ($limit == false || $limit > $read)) {
			if ($offset == 0) {
				$lineA = $lines[$idx];
				$lineB = $lines[$idx+1];
				if ($sort == 'desc') { // file is read backwards
					$lineA = $lines[$idx+1];
					$lineB = $lines[$idx];
				}
				$message = $this->extractMessageMetadata($lineA, $lineB);
				$messageId = $message['message_id'];
				$messages[$messageId] = $message;
				// read message contents on the fly
				if ($includeMessages === true) {
					$messageContents = $this->readMessageContents($messageId);
					$messages[$messageId]["message_contents"] = $messageContents;
				} elseif ($includeMessages === "abstract") {
					$messageAbstract = $this->readMessageAbstract($messageId);
					$messages[$messageId]["message_contents"] = $messageAbstract;
				}
				$read++;
			} else {
				$offset--;
			}
			$idx += 2;
		}
		return $messages;
	}

	protected function searchMessagesInArchive($pattern, $contents=false, $sort='desc', $offset=0, $limit=false) {
		// ensure valid parameters
		if (!is_numeric($offset) || $offset < 0) {
			$offset = 0;
		}
		if (!is_numeric($limit) || $limit <= 0) {
			$limit = null;
		}
		// find
		$pregPattern = $this->convertPatternForPreg($pattern);
		$grepPattern = $this->convertPatternForGrep($pattern);
		if ($pregPattern === false) {
			throw new Exception('Invalid search pattern');
		}
		$archiveDir = $this->listPath . '/archive';
		// grep the pattern in message files only
		$command = $this->findBinary . " $archiveDir -regextype sed -regex " . '"' . $archiveDir . '/[0-9]\+/[0-9]\+$" -exec ' . $this->grepBinary . ' -i -l -R "' . $grepPattern . '" {} +';
		exec($command, $output);
		// message header or attachments might have matched $pattern - extracting
		// message text to ensure the match was not a false positive
		$totalResults = count($output);
		$messages = array();
		foreach ($output as $line) {
			$line = str_replace($archiveDir, '', $line); // strip folder path @TODO find a cleaner way to do this
			$id = intval(str_replace('/', '', $line));
			// message contents is required to check pattern matching
			$message = $this->readMessage($id, true);
			if (
				isset($message["message_contents"]) &&
				isset($message["message_contents"]["text"]) &&
				preg_match($pregPattern, $message["message_contents"]["text"])
			) {
				// if contents was not asked, remove it from results @TODO manage contents=abstract
				if ($contents == false) {
					unset($message["message_contents"]);
				} elseif ($contents == 'abstract') {
					$message["message_contents"]["text"] = $this->abstractize($message["message_contents"]["text"]);
				}
				$messages[] = $message;
			}
		}
		// sort
		usort($messages, array($this, 'sortMessagesById' . ucfirst($sort)));
		// paginate
		$messages = array_slice($messages, $offset, $limit);

		return array(
			"total" => $totalResults,
			"data" => $messages
		);
	}

	/**
	 * Reads and returns metadata for the $id-th message in the current list's archive.
	 * If $contents is true, includes the message contents; if $contents is "abstract",
	 * includes only the first characters of the message; if $silent is false,
	 * will throw an Exception if the message is not found in the archive
	 */
	protected function readMessage($id, $contents=true, $silent=true) {
		list($subfolder, $messageid) = $this->computeSubfolderAndId($id);
		$indexPath = $this->listPath . '/archive/' . $subfolder . '/index';
		// sioux trick to get the 2 lines concerning the message
		$grep = $this->grepBinary . ' "' . $id . ': " "' . $indexPath . '" --no-group-separator -A 1';
		exec($grep, $lines);

		// in case messge was not found in the archive (might happen)
		if (count($lines) == 2) {
			$ret = $this->extractMessageMetadata($lines[0], $lines[1]);
			if ($contents === true) {
				$messageContents = $this->readMessageContents($id);
				$ret["message_contents"] = $messageContents;
			} elseif ($contents === "abstract") {
				$messageAbstract = $this->readMessageAbstract($id);
				$ret["message_contents"] = $messageAbstract;
			}
		} else {
			$ret = $this->messageNotFound($silent);
		}
		return $ret;
	}

	/**
	 * Returns the path of the file containing message n°$id
	 */
	protected function getMessageFileForId($id) {
		// check valid id
		if (! is_numeric($id) || $id <=0) {
			throw new Exception("invalid message id [$id]");
		}
		list($subfolder, $messageId) = $this->computeSubfolderAndId($id);
		$messageFile = $this->listPath . '/archive/' . $subfolder . '/' . $messageId;
		if (! file_exists($messageFile)) {
			throw new Exception("message of id [$id] does not exist");
		}

		return $messageFile;
	}

	protected function readMessageAbstract($id) {
		return $this->readMessageContents($id, true);
	}

	/**
	 * Returns the email address of the author of message n°$id (needs message
	 * to be parsed, beware of resources usage)
	 */
	protected function readMessageAuthorEmail($id) {
		$messageFile = $this->getMessageFileForId($id);
		$parser = new PhpMimeMailParser\Parser();
		$parser->setPath($messageFile);
		$from = $this->extractEmailFromHeader($parser->getHeader('from'));

		return $from;
	}

	/**
	 * Extracts the email address part (ie. "<john@doe.com>") of an email "From:",
	 * "To:", or equivalent header, such as "John DOE <john@doe.com>"
	 */
	protected function extractEmailFromHeader($authorHeader) {
		if (preg_match('/.*<(.+@.+\..+)>/', $authorHeader, $matches)) {
			return $matches[1];
		}
		return false;
	}

	/**
	 * Reads and returns the contents of the $id-th message in the current list's archive
	 * If $abstract is true, reads only the first $this-->settings['messageAbstractSize'] chars
	 * of the message (default 128)
	 */
	protected function readMessageContents($id, $abstract=false) {
		$messageFile = $this->getMessageFileForId($id);
		// read message
		$parser = new PhpMimeMailParser\Parser();
		$parser->setPath($messageFile);
		$text = $parser->getMessageBody('text');
		if ($text) {
			$text = $this->utfize($text);
		}

		$attachments = $parser->getAttachments();
		$attachmentsArray = array();
		foreach ($attachments as $attachment) {
			$attachmentsArray[] = array(
				"filename" => $attachment->filename,
				"content-type" => $attachment->contentType,
				"content-transfer-encoding" => isset($attachment->headers["content-transfer-encoding"]) ? $attachment->headers["content-transfer-encoding"] : null
			);
		}

		if ($abstract) {
			$text = $this->abstractize($text);
		}

		$text = $this->cleanMessageText($text);

		return array(
			'text' => $text,
			'attachments' => $attachmentsArray
		);
	}

	/**
	 * Given a text, returns an abstract limited to 'config->messageAbstractSize'
	 * characters, or 128 if 'config->messageAbstractSize' is not set
	 */
	protected function abstractize($text) { // abstract is a reserved keyword
		if ($text != "") {
			$abstractSize = 128;
			if (! empty($this->settings['messageAbstractSize']) && is_numeric($this->settings['messageAbstractSize']) && $this->settings['messageAbstractSize'] > 0) {
				$abstractSize = $this->settings['messageAbstractSize'];
			}
			// mb_ prevents breaking UTF-8 characters, that cause json_encode to fail
			$text = mb_substr($text, 0, $abstractSize);
		}
		return $text;
	}

	/**
	 * Attempts to remove quotations, headers, markups that could be interpreted
	 * as HTML, and all other sh*t clients send
	 * @TODO improve !
	 */
	protected function cleanMessageText($text) {
		// basic job : remove markups
		$text = str_replace(array('<','>'), array('&lt;','&gt;'), $text);
		return $text;
	}

	/**
	 * Uses php-mime-mail-parser to extract and save attachments to message $id,
	 * into subfolder "attachments" of the associated cache folder; if subfolder
	 * "attachments" already exists and unless $force is true, does nothing.
	 */
	protected function saveMessageAttachments($id, $force=false) {
		$messageCacheFolder = $this->getMessageCacheFolder($id);
		$attachmentsFolder = $messageCacheFolder . '/attachments/';
		$attachmentsFolderExists = is_dir($attachmentsFolder);

		if ($force || ! $attachmentsFolderExists) {
			if (! $attachmentsFolderExists) {
				mkdir($attachmentsFolder);
			}
			$messageFile = $this->getMessageFileForId($id);
			$parser = new PhpMimeMailParser\Parser();
			$parser->setPath($messageFile); 
			$parser->saveAttachments($attachmentsFolder);
		}
	}

	/**
	 * Saves the attachments of message $id in the cache if needed, then returns
	 * the path for attachment $attachmentName; throws an exception if the
	 * required attachment doesn't exist or could not be extracted / saved
	 */
	protected function getMessageAttachmentPath($messageId, $attachmentName) {
		$this->checkValidCache();
		$messageCacheFolder = $this->getMessageCacheFolder($messageId);
		// extract and save attachments
		$this->saveMessageAttachments($messageId);
		$fileName = $messageCacheFolder . '/attachments/' . $attachmentName;
		if (!file_exists($fileName)) {
			throw new Exception("Attachment [$attachmentName] to message [$messageId] does not exist or could not be extracted");
		}
		return $fileName;
	}

	/**
	 * Returns the path of the cache folder for message $id, following
	 * ezmlm-archive's folders convention (ex. message 12743 => folder 127/43)
	 */
	protected function getMessageCacheFolder($id) {
		$f1 = "0";
		$f2 = $id;
		if ($id >= 100) {
			$f1 = floor($id / 100);
			$f2 = $id - (100 * $f1);
		}
		$folderForId = $f1 . '/' . str_pad($f2, 2, "0",STR_PAD_LEFT);
		$folderPath = $this->cachePath . '/' . $this->listName . '/' . $folderForId;
		if (! is_dir($folderPath)) {
			mkdir($folderPath, 0777, true);
		}
		return $folderPath;
	}

	/**
	 * Returns the number of threads existing in the current list's archive
	 * @WARNING assumes that "wc" executable is present in /usr/bin
	 * @WARNING raw counting, does not try to merge linked threads (eg "blah", "Re: blah", "Fwd: blah"...)
	 */
	protected function countThreadsFromArchive() {
		$threadsFolder = $this->listPath . '/archive/threads';
		$command = "cat $threadsFolder/* | /usr/bin/wc -l";
		exec($command, $output);
		//echo "CMD: $command\n";

		return intval($output[0]);
	}

	/**
	 * Reads and returns all threads in the current list's archive, most recent activity first.
	 * If $pattern is set, only returns threads whose subject matches $pattern. Il $limit is set,
	 * only returns the $limit most recent (regarding activity ie. last message id) threads. If
	 * $flMessageDetails is set, returns details for first and last message (take a lot more time)
	 */
	protected function readThreadsFromArchive($pattern=false, $limit=false, $flMessageDetails=false, $sort='desc', $offset=0) {
		$pattern = $this->convertPatternForPreg($pattern);
		// read all threads files in chronological order
		$threadsFolder = $this->listPath . '/archive/threads';
		$threadFiles = scandir($threadsFolder);
		array_shift($threadFiles); // remove "."
		array_shift($threadFiles); // remove ".."
		//var_dump($threadFiles);
		$threads = array();
		foreach ($threadFiles as $tf) {
			$subthreads = file($threadsFolder . '/' . $tf);
			foreach ($subthreads as $st) {
				$thread = $this->parseThreadLine($st, $pattern);
				if ($thread !== false) {
					// might see the same subject hash in multiple thread files (multi-month thread) but
					// thread files are read chronologically so latest data always overwrite previous ones
					$threads[$thread["subject_hash"]] = $thread;
				}
			}
		}

		// attempt to merge linked threads (eg "blah", "Re: blah", "Fwd: blah"...)
		$this->attemptToMergeThreads($threads);

		// sort by last message id descending (newer messages have greater ids) and limit;
		// usort has the advantage of removing natural keys here, thus sending a list whose
		// order will be preserved
		if ($sort == 'asc') {
			usort($threads, array($this, 'sortLeastRecentThreads'));
		} else {
			usort($threads, array($this, 'sortMostRecentThreads'));
		}
		$totalResults = count($threads);
		// offset & limit
		if (!is_numeric($limit) || $limit <= 0) {
			$limit = null;
		}
		if (!is_numeric($offset) || $offset < 0) {
			$offset = 0;
		}
		if ($offset > 0 || $limit != null) {
			$threads = array_slice($threads, $offset, $limit);
		}

		// get subject informations from subjects/ folder (author, first message, last message etc.)
		// @WARNING takes a LOT of time for large lists
		if ($flMessageDetails) {
			foreach ($threads as &$thread) {
				$this->readThreadsFirstAndLastMessageDetails($thread);
				$thread["subject"] = $this->cleanThreadSubject($thread["subject"]);
			}
		} else {
			// clean subjects (and avoid double loop)
			foreach ($threads as &$thread) {
				$thread["subject"] = $this->cleanThreadSubject($thread["subject"]);
			}
		}

		// include all messages ? with contents ?
		return array(
			"total" => $totalResults,
			"data" => $threads
		);
	}

	/**
	 * Returns all threads having at least one message whose date matches the
	 * given $datePortion, using "YYYY[-MM]" format
	 * (ie. "2015" or "2015-04")
	 */
	protected function readThreadsByDate($datePortion, $limit=false, $details=false, $sort='desc', $offset=0) {
		$threadsFolder = $this->listPath . '/archive/threads';
		$year = substr($datePortion, 0, 4);
		$month = substr($datePortion, 5, 2);
		$day = substr($datePortion, 8, 2);
		if (! is_numeric($year)) {
			throw new Exception("invalid date [$year]");
		}
		// file(s) to read
		$filePattern = $year;
		if ($month != false) {
			$filePattern .= $month;
		} else {
			$filePattern .= '*';
		}

		$command = "cat $threadsFolder/$filePattern";
		exec($command, $output);
		//var_dump($output);
		$threads = array();
		foreach ($output as $threadLine) {
			$thread = $this->parseThreadLine($threadLine);
			if ($thread !== false) {
				// might see the same subject hash in multiple thread files (multi-month thread) but
				// thread files are read chronologically so latest data always overwrite previous ones
				$threads[$thread["subject_hash"]] = $thread;
			}
		}

		// attempt to merge linked threads (eg "blah", "Re: blah", "Fwd: blah"...)
		$this->attemptToMergeThreads($threads);

		// sort by last message id descending (newer messages have greater ids) and limit;
		// usort has the advantage of removing natural keys here, thus sending a list whose
		// order will be preserved
		if ($sort == 'asc') {
			usort($threads, array($this, 'sortLeastRecentThreads'));
		} else {
			usort($threads, array($this, 'sortMostRecentThreads'));
		}
		$totalResults = count($threads);
		// offset & limit
		if (!is_numeric($limit) || $limit <= 0) {
			$limit = null;
		}
		if (!is_numeric($offset) || $offset < 0) {
			$offset = 0;
		}
		if ($offset > 0 || $limit != null) {
			$threads = array_slice($threads, $offset, $limit);
		}
		//var_dump($threads); exit;

		// get subject informations from subjects/ folder (author, first message, last message etc.)
		// @WARNING takes a LOT of time for large lists
		if ($details) {
			foreach ($threads as &$thread) {
				$this->readThreadsFirstAndLastMessageDetails($thread);
				$thread["subject"] = $this->cleanThreadSubject($thread["subject"]);
			}
		} else {
			// clean subjects (and avoid double loop)
			foreach ($threads as &$thread) {
				$thread["subject"] = $this->cleanThreadSubject($thread["subject"]);
			}
		}

		// include all messages ? with contents ?
		return array(
			"total" => $totalResults,
			"data" => $threads
		);
	}

	/**
	 * Reads a thread information from the archive; if $details is true, will get details
	 * of first and last message
	 */
	protected function readThread($hash, $details=false) {
		$threadsFolder = $this->listPath . '/archive/threads';
		// find thread hash mention in threads files
		$command = "grep $hash -h -m 1 $threadsFolder/*";
		exec($command, $output);
		if (count($output) == 0) {
			throw new Exception("Thread [$hash] not found in archive");
		}
		$line = $output[0];
		$thread = $this->parseThreadLine($line);
		if ($details) {
			$this->readThreadsFirstAndLastMessageDetails($thread);
		}
		$thread["subject"] = $this->cleanThreadSubject($thread["subject"]);

		return $thread;
	}

	/**
	 * Tries to remove "Re: ", "Fwd: " and so on from thread subject
	 */
	protected function cleanThreadSubject($subject) {
		//echo "AVT: $subject\n";
		$patterns = array('Re:', 'Re :', 'Fwd:', 'Fwd :');
		$subject = str_replace($patterns, '', $subject);
		$subject = trim($subject);
		//echo "APRS: $subject\n";
		return $subject;
	}

	// $pattern is applied here to optimize a little
	protected function parseThreadLine($line, $pattern=false) {
		$thread = false;
		preg_match('/^([0-9]+):([a-z]+) \[([0-9]+)\] (.*)$/', $line, $matches);
		/*if (! isset($matches[1])) {
			echo "LINE: $line<br/>";
			var_dump($matches);
			exit;
		}*/
		if (count($matches) == 5) {
			$lastMessageId = $matches[1];
			$subjectHash = $matches[2];
			$nbMessages = $matches[3];
			$subject = $matches[4];
			if ($pattern == false || preg_match($pattern, $subject)) {
				list($subject, $charsetConverted) = $this->utfizeAndStats($subject);
				$thread = array(
					"last_message_id" => intval($lastMessageId),
					"subject_hash" => $subjectHash,
					"nb_messages" => intval($nbMessages),
					"subject" => $subject,
					"charset_converted" => $charsetConverted
				);
			}
		}
		return $thread;
	}

	/**
	 * Reads the first and last message metadata for thread $thread
	 */
	protected function readThreadsFirstAndLastMessageDetails(&$thread) {
		$thread["last_message"] = $this->readMessage($thread["last_message_id"], false);
		$thread["first_message_id"] = $this->getThreadsFirstMessageId($thread["subject_hash"]);
		// small optimization
		if ($thread["first_message_id"] != $thread["last_message_id"]) {
			$thread["first_message"] = $this->readMessage($thread["first_message_id"], false);
			$thread["last_message"]["subject"] = $this->cleanThreadSubject($thread["last_message"]["subject"]);
		} else {
			$thread["first_message"] = $thread["last_message"];
		}
		// replace thread subject by first message subject, to avoid "Re: ", "Fwd: " etc.
		$thread["subject"] = $thread["first_message"]["subject"];
	}

	/**
	 * Reads the messages from the thread of hash $hash
	 */
	protected function readThreadsMessages($hash, $pattern=false, $contents=false, $limit=false, $sort='desc', $offset=0) {
		//echo "PAT: [$pattern], LIM: [$limit], SOR: [$sort], OFF: [$offset]";
		// check valid sort order
		if ($sort != 'asc') {
			$sort = 'desc';
		}
		$pattern = $this->convertPatternForPreg($pattern);
		$ids = $this->getThreadsMessagesIds($hash);
		$messages = array();
		// sort
		if ($sort == 'desc') {
			$ids = array_reverse($ids);
		}
		// offset & limit
		if (!is_numeric($limit) || $limit <= 0) {
			$limit = null;
		}
		if (!is_numeric($offset) || $offset < 0) {
			$offset = 0;
		}
		// read or search (optimization)
		if ($pattern == false) {
			$totalResults = count($ids);
			if ($offset > 0 || $limit != null) {
				$ids = array_slice($ids, $offset, $limit);
			}
			// read messages
			foreach ($ids as $id) {
				$messages[] = $this->readMessage($id, $contents);
			}
		} else {
			// search messages
			foreach ($ids as $id) {
				$message = $this->readMessage($id, $contents);
				if ($pattern == false || preg_match($pattern, $message["message_contents"]["text"])) {
					$messages[] = $message;
				}
			}
			$totalResults = count($messages);
			// offset & limit
			if ($offset > 0 || $limit != null) {
				$messages = array_slice($messages, $offset, $limit);
			}
		}

		//return $messages;
		return array(
			"total" => $totalResults,
			"data" => $messages
		);
	}

	/**
	 * Returns the id of the first message in the thread of hash $hash
	 */
	protected function getThreadsFirstMessageId($hash) {
		$ret = 0; // error flag (no message has the ID 0 in the archive)
		$ids = $this->getThreadsMessagesIds($hash, 1);
		if (isset($ids[0])) {
			$ret = $ids[0];
		}
		return $ret;
	}

	/**
	 * Returns the ids of the $limit first messages in the thread of hash $hash,
	 * (first means oldest @TODO inconsistent with other methods, change this ?)
	 */
	protected function getThreadsMessagesIds($hash, $limit=false) {
		$subjectFile = $this->getSubjectFile($hash);
		// read 2nd line (1st message)
		$command = $this->grepBinary;
		if (is_numeric($limit) && $limit > 0) {
			$command .= " -m $limit";
		}
		$command .= " '^.*[0-9]\+:[0-9]\+:[a-z]\+ .\+$' $subjectFile";
		exec($command, $output);
		//exit;
		$ids = array();
		foreach ($output as $line) {
			// regexp starts with .* because sometimes the subject (1st line) contains a \n and
			// thus contaminates the second line (wtf?)
			preg_match('/^(.*[^0-9])?([0-9]+):([0-9]+):([a-z]+) (.+)$/', $line, $matches);
			$ids[] = intval($matches[2]);
		}
		return $ids;
	}

	/**
	 * Computes and returns the path of the file in /subjects archive folder
	 * that concerns the subject of hash $hash
	 */
	protected function getSubjectFile($hash) {
		$hash2f = substr($hash,0,2);
		$hashEnd = substr($hash,2);
		$subjectsFolder = $this->listPath . '/archive/subjects';
		$subjectFile = $subjectsFolder . '/' . $hash2f . '/' . $hashEnd;
		return $subjectFile;
	}

	/**
	 * Tries to detect subjects that were incorrectly separated into 2 or more threads, because
	 * of encoding problems, "Re: " ou "Fwd: " mentions, and so on; @WARNING 2 totally different
	 * threads might have the same subject string, so make sure there really was an encoding or
	 * mention problem before merging !
	 * @TODO do it !
	 */
	protected function attemptToMergeThreads(&$threads) {
		// detect if subject string is problematic
		// try to RAM it (reductio ad minima)
		// try to merge it or overwrite subject with RAMed subject
	}

	// ------------------ API METHODS -----------------------------

	/**
	 * Sets the current domain to $domain and recomputes paths
	 */
	public function setDomain($domain) {
		$this->domainName = $domain;
		$this->domainPath = $this->domainsPath . '/' . $this->domainName;
		$this->chdirToDomain();
	}

	/**
	 * Returns the current domain (should always be set)
	 */
	public function getDomain() {
		return $this->domainName;
	}

	/**
	 * Sets the current list to $listName and recomputes paths
	 */
	public function setListName($listName) {
		$this->listName = $listName;
		if (! is_dir($this->domainPath)) {
			throw new Exception("please set a valid domain path before setting list name (current domain path: [" . $this->domainPath . "])");
		}
		$this->listPath = $this->domainPath . '/' . $this->listName;
	}

	/**
	 * Returns the current list
	 */
	public function getListName() {
		return $this->listName;
	}

	public function getLists($pattern=false) {
		$pattern = $this->convertPatternForPreg($pattern);
		$dirP = opendir('.');
		$lists = array();
		while ($subdir = readdir($dirP)) {
			if (is_dir($subdir) && substr($subdir, 0, 1) != '.') {
				// presence of "lock" file means this is a list (ezmlm-web's strategy)
				if ($this->fileExistsInDir('lock', $subdir)) {
					if ($pattern != false) {
						// exclude from results if list name doesn't match search pattern
						if (preg_match($pattern, $subdir) != 1) {
							continue;
						}
					}
					$lists[] = $subdir;
				}
			}
		}
		closedir($dirP);
		sort($lists); // @TODO ignore case ?
		return $lists;
	}

	/**
	 * Returns basic information about a list
	 */
	public function getListInfo() {
		$info = array();
		$info['list_name'] = $this->listName;
		$info['list_address'] = $this->listName . '@' . $this->domainName;
		$info['nb_threads'] = $this->countAllThreads();
		$info['nb_messages'] = $this->countAllMessages();
		$firstMessage = $this->readMessagesFromArchive(false, 1, 'asc');
		$lastMessage = $this->readMessagesFromArchive(false, 1, 'desc');
		$info['first_message'] = isset($firstMessage[0]) ? $firstMessage[0] : false;
		$info['last_message'] = isset($lastMessage[0]) ? $lastMessage[0] : false;
		return $info;
	}

	/**
	 * Returns the "calendar" for a list : a summary of the number of messages
	 * per month per year (glad the threads are sorted by month in the archive !)
	 */
	public function getListCalendar() {
		$archiveFolder = $this->listPath . '/archive';
		// grep messages dates amon all archive indexes - cannot be done with thread
		// files cause although they are sorted by month, the number of messages
		// they mention for each thread is culumated through all months of the
		// list existence
		$command = $this->grepBinary . ' -oPh "[0-9]{1,2} \K[a-zA-Z]{3} [0-9]{4} " ' . $archiveFolder . '/*/index';
		exec($command, $output);
		//var_dump($output);

		$months = array();
		foreach ($output as $line) {
			// indices
			$month = $this->monthsNumbers[strtolower(substr($line, 0, 3))];
			$year = substr($line, 4, 4);
			if (! isset($months[$year])) {
				$months[$year] = array();
			}
			if (isset($months[$year][$month])) {
				$months[$year][$month]++;
			} else {
				$months[$year][$month] = 1;
			}
		}
		return $months;
	}

	public function addList($name, $options=null) {
		$this->checkValidDomain();
		if ($this->listExists($name)) {
			throw new Exception("list [$name] already exists");
		} else {
			$this->setListName($name);
		}
		// convert options string (ex: "aBuD") to command switches (ex: "-a -B -u -D")
		$switches = $this->getSwitches($options);
		$dotQmailFile = $this->domainPath . '/.qmail-' . $this->listName;
		$commandOptions = $switches . ' ' . $this->listPath . ' ' . $dotQmailFile . ' ' . $this->listName . ' ' . $this->domainName;
		//echo "CO: $commandOptions\n";
		$ret = $this->rt('ezmlm-make', $commandOptions);
		if ($ret) {
			$this->setReplyToHeader();
		}
		return $ret;
	}

	/**
	 * deletes a list : .qmail-[listname]-* files and /[listname] folder
	 */
	public function deleteList() {
		$this->checkValidList();
		$dotQmailFilesPrefix = $this->domainPath . '/.qmail-' . $this->listName;
		// list of .qmail files @WARNING depends on the options set when creating the list
		$dotQmailFiles = array(
			"", // list main file
			"-accept-default",
			"-default",
			"-digest-owner",
			"-digest-return-default",
			"-owner",
			"-reject-default",
			"-return-default"
		);

		$out = array();
		$ret = 0;
		// delete list directory
		if (is_dir($this->listPath)) {
			$command = 'rm -r ' . $this->listPath;
			//echo $command . "\n";
			exec($command, $out, $retcode);
			$ret += $retcode;
		} else {
			throw new Exception("list [" . $this->listName . "] does not exist");
		}
		// delete all .qmail files
		foreach ($dotQmailFiles as $dqmf) {
			$filePath = $dotQmailFilesPrefix . $dqmf;
			//echo "Testing [$filePath]:\n";
			if (file_exists($filePath) || is_link($filePath)) { // .qmail files are usually links, file_exists() returns false
				$command = 'rm ' . $filePath;
				//echo "command: " . $command . "\n";
				exec($command, $out, $retcode);
				$ret += $retcode;
			}
		}
		// status
		if ($ret > 0) {
			throw new Exception("error while deleting list files");
		}
		return true;
	}

	public function getSubscribers() {
		$this->checkValidList();
		$command = "ezmlm-list";
		$options = $this->listPath;
		$ret = $this->rt($command, $options, true);
		// ezmlm returns one result per line
		$ret = array_filter(explode("\n", $ret));
		return $ret;
	}

	public function getModerators() {
		$this->checkValidList();
		$command = "ezmlm-list";
		$options = $this->listPath . '/mod';
		$ret = $this->rt($command, $options, true);
		// ezmlm returns one result per line
		$ret = array_filter(explode("\n", $ret));
		return $ret;
	}

	public function getPosters() {
		$this->checkValidList();
		$command = "ezmlm-list";
		$options = $this->listPath . '/allow';
		$ret = $this->rt($command, $options, true);
		// ezmlm returns one result per line
		$ret = array_filter(explode("\n", $ret));
		return $ret;
	}

	public function addSubscriber($subscriberEmail) {
		$this->checkValidEmail($subscriberEmail);
		$command = "ezmlm-sub";
		$options = $this->listPath . ' ' . $subscriberEmail;
		$ret = $this->rt($command, $options);
		return $ret;
	}

	public function deleteSubscriber($subscriberEmail) {
		$this->checkValidEmail($subscriberEmail);
		$command = "ezmlm-unsub";
		$options = $this->listPath . ' ' . $subscriberEmail;
		$ret = $this->rt($command, $options);
		return $ret;
	}

	public function addModerator($moderatorEmail) {
		$this->checkValidEmail($moderatorEmail);
		$command = "ezmlm-sub";
		$options = $this->listPath . '/mod ' . $moderatorEmail;
		$ret = $this->rt($command, $options);
		return $ret;
	}

	public function deleteModerator($moderatorEmail) {
		$this->checkValidEmail($moderatorEmail);
		$command = "ezmlm-unsub";
		$options = $this->listPath . '/mod ' . $moderatorEmail;
		$ret = $this->rt($command, $options);
		return $ret;
	}

	public function addPoster($posterEmail) {
		$this->checkValidEmail($posterEmail);
		$command = "ezmlm-sub";
		$options = $this->listPath . '/allow ' . $posterEmail;
		$ret = $this->rt($command, $options);
		return $ret;
	}

	public function deletePoster($posterEmail) {
		$this->checkValidEmail($posterEmail);
		$command = "ezmlm-unsub";
		$options = $this->listPath . '/allow ' . $posterEmail;
		$ret = $this->rt($command, $options);
		return $ret;
	}

	/**
	 * Sends a message to the current list, using the SMTP server from the
	 * config file, and the identity of the currently logged-in user (using SSO
	 * authentication)
	 */
	public function sendMessage($data, $threadHash=null) {
		$mail = new PHPMailer();

		$user = $this->authAdapter->getUser();
		//var_dump($user);
		if ($user == null) {
			throw new Exception('you must be logged in to post messages');
		}

		// info needed :
		// 1) from (logged-in person's email address + alias)
		$authFrom = $this->authAdapter->getUserEmail();
		$from = $authFrom;
		if (isset($data['from'])) {
			$from = $data['from'];
			// identity usurpation ? check rights !
			if ($authFrom != $from && ! $this->authAdapter->isAdmin()) {
				throw new Exception("thou shall not usurpate your neighbour's identity unless you are The Admin");
			}
		}
		$authFromAlias = $this->authAdapter->getUserFullName();
		$fromAlias = $authFromAlias;
		if (isset($data['from_alias'])) {
			$fromAlias = $data['from_alias'];
			// identity usurpation ? check rights !
			if ($authFrom != $fromAlias && ! $this->authAdapter->isAdmin()) {
				throw new Exception("thou shall not misspel your neighbour's name unless you are The Admin");
			}
		}

		// 2) to (list address + alias)
		$listInfo = $this->getListInfo();
		//var_dump($listInfo);
		$to = $listInfo['list_address'];
		$toAlias = $listInfo['list_name'];

		// 3) subject (new or read from subjectHash)
		if ($threadHash != "") {
			$threadInfo = $this->getThread($threadHash);
			//var_dump($threadInfo);
			$subject = $threadInfo['subject'];
		} elseif (! empty($data['subject'])) {
			$subject = $data['subject'];
		} else {
			throw new Exception("please provide either a threadHash parameter or a 'subject' field in JSON data");
		}

		// 4) attachments (base64 from POST data)

		// - format (HTML)
		// - body (HTML + text)
		
		// RECAP !
		echo "FROM: $from\n";
		echo "FROM ALIAS: $fromAlias\n";
		echo "TO: $to\n";
		echo "TO ALIAS: $toAlias\n";
		echo "SUBJECT: $subject\n";
		exit;

		//$mail->SMTPDebug = 3;                               // Enable verbose debug output
		//$mail->isSMTP();                                      // Set mailer to use SMTP
		//$mail->Host = 'smtp1.example.com;smtp2.example.com';  // Specify main and backup SMTP servers
		//$mail->SMTPAuth = true;                               // Enable SMTP authentication
		//$mail->Username = 'user@example.com';                 // SMTP username
		//$mail->Password = 'secret';                           // SMTP password
		//$mail->SMTPSecure = 'tls';                            // Enable TLS encryption, `ssl` also accepted
		//$mail->Port = 587;                                    // TCP port to connect to

		$mail->setFrom('from@example.com', 'Mailer');
		$mail->addAddress('joe@example.net', 'Joe User');     // Add a recipient
		$mail->addAddress('ellen@example.com');               // Name is optional
		$mail->addReplyTo('info@example.com', 'Information');
		$mail->addCC('cc@example.com');
		$mail->addBCC('bcc@example.com');

		$mail->addAttachment('/var/tmp/file.tar.gz');         // Add attachments
		$mail->addAttachment('/tmp/image.jpg', 'new.jpg');    // Optional name
		$mail->isHTML(true);                                  // Set email format to HTML

		$mail->setLanguage('fr');

		$mail->Subject = 'Here is the subject';
		$mail->Body    = 'This is the HTML message body <b>in bold!</b>';
		$mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

		if(!$mail->send()) {
			echo 'Message could not be sent.';
			echo 'Mailer Error: ' . $mail->ErrorInfo;
		} else {
			echo 'Message has been sent';
		}
	}

	public function countAllMessages() {
		$this->checkValidList();
		$nb = $this->countMessagesFromArchive();
		return $nb;
	}

	public function getAllMessages($contents=false, $sort='desc', $offset=0, $limit=false) {
		$this->checkValidList();
		$msgs = $this->readMessagesFromArchive($contents, $limit, $sort, $offset);
		$nbMsgs = $this->countAllMessages(); // harmonizing return format @WARNING sub-optimal
		return array(
			"total" => $nbMsgs,
			"data" => $msgs
		);
	}

	public function getMessagesByDate($datePortion, $contents=false, $limit=false, $sort='desc', $offset=0) {
		$this->checkValidList();
		$threads = $this->readMessagesByDate($datePortion, $contents, $limit, $sort, $offset);
		return $threads;
	}

	public function getLatestMessages($contents=false, $limit=10, $sort='desc') {
		$this->checkValidList();
		$msgs = $this->readMessagesFromArchive($contents, $limit, $sort);
		return $msgs;
	}

	public function searchMessages($pattern, $contents=false, $sort='desc', $offset=0, $limit=false) {
		$this->checkValidList();
		$msgs = $this->searchMessagesInArchive($pattern, $contents, $sort, $offset, $limit);
		return $msgs;
	}

	public function getMessage($id, $contents=true) {
		$this->checkValidList();
		$msg = $this->readMessage($id, $contents, false);
		return $msg;
	}

	public function getAttachmentPath($messageId, $attachmentName) {
		$this->checkValidList();
		$path = $this->getMessageAttachmentPath($messageId, $attachmentName);
		return $path;
	}

	public function getAllThreads($pattern=false, $limit=false, $details=false, $sort='desc', $offset=0) {
		$this->checkValidList();
		$threads = $this->readThreadsFromArchive($pattern, $limit, $details, $sort, $offset);
		return $threads;
	}

	public function getThreadsByDate($datePortion, $limit=false, $details=false, $sort='desc', $offset=0) {
		$this->checkValidList();
		$threads = $this->readThreadsByDate($datePortion, $limit, $details, $sort, $offset);
		return $threads;
	}

	public function countAllThreads() {
		$this->checkValidList();
		$nb = $this->countThreadsFromArchive();
		return $nb;
	}

	public function getLatestThreads($limit=10, $details=false) {
		$this->checkValidList();
		$threads = $this->readThreadsFromArchive(false, $limit, $details);
		return $threads;
	}

	public function getThread($hash, $details=true) {
		$this->checkValidList();
		$thread = $this->readThread($hash, $details);
		return $thread;
	}

	public function getAllMessagesByThread($hash, $pattern=false, $contents=false, $sort='desc', $offset=0, $limit=false) {
		$this->checkValidList();
		// if searching is required, message contents will have to be extracted to search among it
		$callTimeContents = $contents;
		if ($pattern !== false) {
			$callTimeContents = true;
		}
		$messages = $this->readThreadsMessages($hash, $pattern, $callTimeContents, $limit, $sort, $offset);
		// in case of non-false $pattern but false $contents, remove messages contents before sending
		if ($pattern !== false && $contents !== true) {
			foreach ($messages as &$mess) {
				if ($contents == 'abstract') {
					$mess["message_contents"]["text"] = $this->abstractize($mess["message_contents"]["text"]);
				} else { // $contents == false
					unset($mess["message_contents"]);
				}
			}
		}
		return $messages;
	}

	public function getLatestMessagesByThread($hash, $limit=10, $contents=false, $sort='desc') {
		$this->checkValidList();
		$messages = $this->readThreadsMessages($hash, false, $contents, $limit, $sort);
		return $messages;
	}

	public function countMessagesFromThread($hash) {
		$this->checkValidList();
		$thread = $this->readThread($hash);
		$nb = $thread["nb_messages"];
		return $nb;
	}

	public function getPreviousMessageByThread($hash, $id, $contents=true) {
		$this->checkValidList();
		$ids = $this->getThreadsMessagesIds($hash);
		$key = array_search($id, $ids);
		if ($key === false) {
			throw new Exception("Message [$id] not found in thread [$hash]");
		}
		// next message has a lower key in the array
		if ($key == 0) {
			// @TODO send something nicer, without error ?
			throw new Exception("Message [$id] is the first in thread [$hash]");
		}
		$previousMessage = $this->readMessage($ids[$key-1], $contents);
		return $previousMessage;
	}

	public function getNextMessageByThread($hash, $id, $contents=true) {
		$this->checkValidList();
		$ids = $this->getThreadsMessagesIds($hash);
		$key = array_search($id, $ids);
		if ($key === false) {
			throw new Exception("Message [$id] not found in thread [$hash]");
		}
		// next message has a greater key in the array
		if ($key == count($ids)-1) {
			// @TODO send something nicer, without error ?
			throw new Exception("Message [$id] is the most recent in thread [$hash]");
		}
		$nextMessage = $this->readMessage($ids[$key+1], $contents);
		return $nextMessage;
	}
}
