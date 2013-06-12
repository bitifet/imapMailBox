<?php
// imapMailBox.class.php
// =====================
//
// Class easily to handle imap/pop3 email.
// Borrowed from (thanks to) Wil Barath's comment at phpdoc:
// 	http://php.net/manual/es/intro.imap.php#96415
//
// @author: Joan Miquel Torres<jmtorres@112ib.com>
// @Company: Gestió d'Emergències de les Illes Balears SAU (GEIBSAU)
// @Credits: Will Barath (http://php.net/manual/es/intro.imap.php#96415)


class imapMailBox {

	///private $con;
	public $con;
	private $mailCache;


	public function __construct (/*{{{*/
		$conStr,
		$conPrm
	) {
		$this->login ($conStr, $conPrm);
	}/*}}}*/


	function login(/*{{{*/
		$account,
		$prm = array()
	) {
		// FIXME!!! ¿¿ $folder ??

		// Get parameters from $account string:/*{{{*/
		if (is_string ($account)) {
			if (! preg_match (
				'/^(?:(.*?)(?::(.*?))@)?(?:\{(.*?)(?::(\d+))?(?:\/(.*?))?\})(.*?)?$/',
				$account,
				$matches
			)) throw new Exception (
				"Wrong connection string."
			);
			@ list ($foo, $user, $pass, $host, $port, $proto, $folder) = $matches;
		} else if (is_array ($account)) {
			$prm = $account;
		};/*}}}*/

		// Override $host,$port,$user,$pass,$folder,$ssl with $prm specifications./*{{{*/
		foreach (
			array (
				'host',
				'port', // Numeric.
				'proto', // 'imap', 'pop3', 'pop3/ssl', 'pop3/ssl/<options>'...
				'ssl', // Boolean
				'user',
				'pass',
				'folder',
				'n_retries',
				'params'
			)
			as $p
		) if ( ! is_null (@ $prm[$p])) $$p = $prm[$p];
		/*}}}*/

		$options = // Build options modifier (see http://docs.php.net/manual/en/function.imap-open.php)/*{{{*/
		0; // Default.
		@ $prm['OP_READONLY'] && $options |= OP_READONLY; // Abrir un buzón de sólo lectura
		@ $prm['OP_ANONYMOUS'] && $options |= OP_ANONYMOUS; // No usar o actualizar un .newsrc para noticias (sólo NNTP)
		@ $prm['OP_HALFOPEN'] && $options |= OP_HALFOPEN; // Para nombres IMAP y NNTP, abrir una conexión pero no abrir el buzón.
		@ $prm['CL_EXPUNGE'] && $options |= CL_EXPUNGE; // Expurgar el buzón automáticamente al cierre del mismo (véase también imap_delete() y imap_expunge())
		@ $prm['OP_DEBUG'] && $options |= OP_DEBUG; // Depurar negociaciones de protocolo
		@ $prm['OP_SHORTCACHE'] && $options |= OP_SHORTCACHE; // Almacenamiento en caché corto (sólo elt)
		@ $prm['OP_SILENT'] && $options |= OP_SILENT; // No dejar pasar eventos (uso interno)
		@ $prm['OP_PROTOTYPE'] && $options |= OP_PROTOTYPE; // Devolver el prototipo de controlador
		@ $prm['OP_SECURE'] && $options |= OP_SECURE; // No realizar autenticación no segura
		/*}}}*/

		// Check for minimal parameters:/*{{{*/
		if (is_string (@ $params)) try { // Accept json instead of array:
			$params = (array) json_decode ($params);
		} catch (Exception $e) {
			throw new Exception ("Bad 'params' specification");
		};
		if (! strlen (@ $host)) throw new Exception (
			"Host not specified"
		);
		@ $n_retries += 0;
		/*}}}*/

		// Set defaults when not overriden:/*{{{*/
		strlen (@ $folder) || $folder = "INBOX";
		strlen (@ $proto) || $proto = "imap";
		/*}}}*/

		// Build final connection string:/*{{{*/
		$strCon = (
			strlen (@ $con)
				? $con
				: ($host
					. (strlen(@ $port) ? ':' . ($port + 0) : '')
					. (strlen(@ $proto) ? '/' . $proto : '')
					. ((false === @ $ssl) ? '/novalidate-cert' : '')
				)
			)
		;
		if ($strCon[0] !== '{') $strCon = "{{$strCon}}"; // Surround with '{}'.
		/*}}}*/

		// Try to connect:/*{{{*/
		return is_resource (
			$this->con = @ imap_open($strCon, $user, $pass, $options, $n_retries, $params)
		);/*}}}*/

	}/*}}}*/


	function stat(/*{{{*/
	) {
		if (! is_resource ($this->con)) throw new Exception ('Mailbox not opened');
		$check = imap_mailboxmsginfo($this->con);
		return ((array)$check);
	}/*}}}*/


	function listMessages (/*{{{*/
		$message=""
	) {
		if (! is_resource ($this->con)) throw new Exception ('Mailbox not opened');
		if ($message)
		{
			$range=$message;
		} else {
			$MC = imap_check($this->con);
			$range = "1:".$MC->Nmsgs;
		}
		$response = imap_fetch_overview($this->con,$range);
		foreach ($response as $msg) {
			$msgdata = array();
			foreach (
				(array)$msg
				as $k => $v
			) $msgdata[$k] = imap_utf8($v);

			$result[$msg->msgno] = $msgdata;
		};
		return $result;
	}/*}}}*/


	function retrHeaders (/*{{{*/
		$message
	) {
		if (! is_resource ($this->con)) throw new Exception ('Mailbox not opened');
		return(imap_fetchheader($this->con,$message,FT_PREFETCHTEXT));
	}/*}}}*/


	function parseHeaders(/*{{{*/
		$headers
	) {
		if (! is_resource ($this->con)) throw new Exception ('Mailbox not opened');
		if (is_numeric($headers)) $headers = $this->retrHeaders ($headers);
		$headers=preg_replace('/\r\n\s+/m', '',$headers);
		preg_match_all('/([^: ]+): *(.+?(?:\r\n\s(?:.+?))*)?\r\n/m', $headers, $matches);
		foreach ($matches[1] as $key =>$value) $result[$value]=imap_utf8($matches[2][$key]); // FIXME (or not)
		return($result);
	}/*}}}*/


	private function decode_part(/*{{{*/
		$message_number,$part,$prefix
	) {
		$attachment = array();

		// Fetch and decode parameters:/*{{{*/
		$attachment['is_attachment'] = false;
		if($part->ifdparameters) {
			foreach($part->dparameters as $object) {
				$attName = strtolower($object->attribute);
				$attachment[$attName]=imap_utf8($object->value);
				if($attName == 'filename') $attachment['is_attachment'] = true;
			}
		}
		if($part->ifparameters) {
			foreach($part->parameters as $object) {
				$attName = strtolower($object->attribute);
				$attachment[$attName]=imap_utf8($object->value);
				if($attName == 'name') $attachment['is_attachment'] = true;
			}
		}
		if ($attachment['is_attachment']) {
			isset ($attachment['filename']) || $attachment['filename'] = $attachment['name'];
			isset ($attachment['name']) || $attachment['filename'] = $attachment['filename'];
		};
		/*}}}*/

		// Fetch and decode data:/*{{{*/
		$attachment['data'] = imap_fetchbody($this->con, $message_number, $prefix);
		if($part->encoding == 3) { // 3 = BASE64
			$attachment['data'] = base64_decode($attachment['data']);
		}
		elseif($part->encoding == 4) { // 4 = QUOTED-PRINTABLE
			$attachment['data'] = quoted_printable_decode($attachment['data']);
		}
		/*}}}*/

		return($attachment);
	}/*}}}*/

	private function get_parts(/*{{{*/
		$mid,$part,$prefix
	) {   
		if (! is_resource ($this->con)) throw new Exception ('Mailbox not opened');
		$attachments=array();
		$attachments[$prefix] = $this->decode_part($mid,$part,$prefix);
		if (isset($part->parts)) // multipart
		{
			$prefix = ($prefix == "0")?"":"$prefix.";
			foreach ($part->parts as $number=>$subpart)
				$attachments=array_merge($attachments, $this->get_parts($mid,$subpart,$prefix.($number+1)));
		}
		return $attachments;
	}/*}}}*/


	function mime_to_array(/*{{{*/
		$mid,$parseHeaders=false
	) {
		if (! is_resource ($this->con)) throw new Exception ('Mailbox not opened');

		// Retrive from cache (if already cached):/*{{{*/
		if (
			@ $this->mailCache['id'] === $mid
		) {
			return $this->mailCache['data'];
		};/*}}}*/

		@ $mail = imap_fetchstructure($this->con,$mid);
		if ($mail === false) return false; // Abort if mail not exists.
		$mail = $this->get_parts($mid,$mail,0);
		if ($parseHeaders) $mail[0]["parsed"]=$this->parseHeaders($mail[0]["data"]);
		// $mail = array (
		// 	0 => array ( // headers
		// 		'boundary' => boundary string.
		// 		'data' => raw header section.
		// 		'parsed' (if requested) => array(headers).
		// 	1 => body
		// 	1.1 => body::text (if multipart)
		// 	1.2 => body::html (if multipart)
		// 	2 => First attachment.
		// 	3 => Second attachment.
		// )
		//
		// IMPORTANT:
		// 	a) Not sure that body ALWAYS will be the first part.
		// 	b) Attachments can be embeeded multiparts.

		// Remember for (nearly) future requests:/*{{{*/
		$this->mailCache = array (
			'id' => $mid,
			'data' => $mail
		);/*}}}*/

		return($mail);
	}/*}}}*/


	public function getAttachments (/*{{{*/
		$mid
	) {
		if (! is_resource ($this->con)) throw new Exception ('Mailbox not opened');
		if (false === $mail = $this->mime_to_array ($mid)) return false;

		$att = array();
		
		foreach (
			$mail
			as $i => $data
		) if (
			@ $data['is_attachment'] === true
		) $att[] = $data;

		return $att;
	}/*}}}*/


	private function fixutf ($text) {/*{{{*/

		$text .= ' '; // <Guorrkarround>

		$srcenc = mb_detect_encoding(/*{{{*/
			$text,
			array (
				'UTF-8',
				'ISO-8859-15',
				'ISO-8859-1',
				'JIS',
				'eucjp-win',
				'sjis-win'
			)
		);/*}}}*/

		$text = mb_convert_encoding ($text, 'UTF-8', $srcenc);

		$text = substr($text, 0, -1); // <Guorrkarround>

		// Miscellaneous (commonly good) replacements:/*{{{*/
		$replacements = array (
			chr(0xc2) . chr(0x92) => "'"
		);
		$text = str_replace (
			array_keys ($replacements),
			array_values ($replacements),
			$text
		);
		/*}}}*/

		return $text;

	}/*}}}*/


	private function isHtml ($txt) {/*{{{*/
		$prmP = "(?:\s+.*?)?";
		if ( preg_match (/*{{{*/
			"/^\s*(?:<doctype{$prmP}>\s*)?<html{$prmP}>.*?<\/html>.*$/ims",
			$txt
		)) return true;/*}}}*/
		if ( preg_match (/*{{{*/
			"/^\s*<div{$prmP}>.*<\/div>\s*$/ims",
			$txt
		)) return true;/*}}}*/
		return false;
	}/*}}}*/


	public function getBody (/*{{{*/
		$mid,
		$format = 'txt'
	) {
		if (! is_resource ($this->con)) throw new Exception ('Mailbox not opened');
		if (false === $mail = $this->mime_to_array ($mid)) return false;

		switch (strtolower(trim($format))) { // Normalyze / check $format./*{{{*/
		case 'txt':/*{{{*/
		case 'text':
			$format = 'txt';
			break;/*}}}*/
		case 'html':/*{{{*/
		case 'htm':
			$format = 'html';
			break;/*}}}*/
		default:/*{{{*/
			throw new Exception (
				"Unknown body format: {$format}"
			);/*}}}*/
		};/*}}}*/


		$parts = array();
		$bodyPart = null; // (Unknown).
		$body = null;
		foreach (
			$mail
			as $i => $data
		) if (
			$i !== 0 // Ignore header part.
			&& @ $data['is_attachment'] === false // Not attachment.
		) {

			if (preg_match ( // Determine part and subpart índex:/*{{{*/
				'/^([^.]+?)\\.([^.]+)$/', // Top-level subpart.
				$i,
				$matches
			)) {
				list ($foo, $part, $subpart) = $matches;
			} else if (false === strpos ('.', $i)) { // Top-level part.
				$part = $i;
				$subpart = 0; // Use '0' to mean "not a subpart".
			} else { // Non top-level subpart.
				continue; // (Ignore).
			};/*}}}*/

			if (is_null ($bodyPart)) { // Body is first non-attachment part./*{{{*/
				$bodyPart = $part;
			} else if ($bodyPart != $part) continue;/*}}}*/

			if ($subpart) {

				$isHtml = $this->isHtml ($data['data']);

				if ($format == 'txt') {/*{{{*/
					if ($isHtml) {
						is_null($body) && $body = html_entity_decode(strip_tags($data['data'])); // Fix buggy emails without text part.
					} else $body = $data['data']; // That's what we want.
				} else {
					if (! $isHtml) {
						if (strlen (trim ($data['data']))) {
							is_null($body) && $body = nl2br ($data['data']); // Minimal html default.
						};
					} else $body = $data['data']; // That's what we want.
				};/*}}}*/

			} else if (isset ($mail["{$part}.1"])) { // Multipart
				continue;
			} else { // No multipart.
				$body = ($format == 'html')
					? nl2br ($data['data'])
					: $data['data']
				;
			};

		};

		if (! mb_check_encoding ($body, 'utf-8')) {
			$body = $this->fixutf($body);
		};

		return $body;

	}/*}}}*/


	public function getRaw(
		$mid
	) {
		return imap_fetchheader($this->con, $mid)
			. imap_body($this->con, $mid);
	}

	// Untested:
	function dele(/*{{{*/
		$message
	) {
		if (! is_resource ($this->con)) throw new Exception ('Mailbox not opened');
		return(imap_delete($this->con,$message));
	}/*}}}*/


};








