<?php
/**!info**
{
  "Plugin Name"  : "Admin report generator",
  "Plugin URI"   : "http://enanocms.org/plugin/adminreport",
  "Description"  : "Allow users to report bugs with the site, including with automatic links that fill everything in.",
  "Author"       : "Dan Fuhry",
  "Version"      : "1.0",
  "Author URI"   : "http://enanocms.org/"
}
**!*/

$plugins->attachHook('session_started', 'register_special_page(\'AdminReport\', \'Report site bug\', true);');

function page_Special_AdminReport()
{
	global $db, $session, $paths, $template, $plugins; // Common objects
	global $output;
	
	// parse parameters
	$parms = str_replace('_', ' ', dirtify_page_id($paths->getAllParams()));
	$replaces = array();
	if ( preg_match_all('/<(.+?)>/', $parms, $matches) )
	{
		foreach ( $matches[0] as $i => $match )
		{
			$replaces[] = $matches[1][$i];
			$parms = str_replace_once($match, "\${$i}\$", $parms);
		}
	}
	
	$parms = explode('/', $parms);
	$info = array(
			'page' => '',
			'comment' => ''
		);
	foreach ( $parms as $parm )
	{
		list($name) = explode('=', $parm);
		$info[$name] = substr($parm, strlen($name)+1);
		foreach ( $replaces as $i => $val )
		{
			$info[$name] = str_replace_once("\${$i}\$", $val, $info[$name]);
		}
	}
	
	$output->header();
	
	$errors = array();
	if ( isset($_POST['submit']) )
	{
		$page = $_POST['page'];
		$comment = trim($_POST['comment']);
		$captcha_input = $_POST['captcha_response'];
		$captcha_id = $_POST['captcha_id'];
		if ( strtolower($captcha_input) !== ($correct = strtolower($session->get_captcha($captcha_id))) )
		{
			$errors[] = 'The confirmation code you entered was incorrect. '; // . "($captcha_input vs. $correct)";
		}
		$session->kill_captcha();
		if ( empty($comment) )
		{
			$errors[] = 'Please enter a description of the problem.';
		}
		else
		{
			$info['comment'] = $comment;
		}
		
		if ( empty($errors) )
		{
			$email = getConfig('contact_email');
			
			if ( !is_array($result = arp_send_mail($email, "[{$_SERVER['HTTP_HOST']}] Website bug report", "Sent from IP: {$_SERVER['REMOTE_ADDR']}\n\n---------------------------\n$comment")) )
			{
				redirect(makeUrl($page), 'Report sent', 'Thank you, your report has been sent. Redirecting you back to the page...', 5);
			}
			else
			{
				$errors = $result;
			}
		}
		
		$info['page'] = $_POST['page'];
	}
	
	$captchacode = $session->make_captcha();
	if ( !empty($errors) )
	{
		echo '<div class="error-box-mini"><ul><li>' .
				implode('</li><li>', $errors) .
				'</li></ul></div>';
	}
	?>
	<form method="post" action="<?php echo makeUrl($paths->page); ?>">
		<div class="tblholder">
			<table border="0" cellspacing="1" cellpadding="4">
				<tr>
					<th colspan="2">Report a site bug</th>
				</tr>
				<tr>
					<td class="row1">
						URL of page:
					</td>
					<td class="row1">
						http<?php if ( $GLOBALS['is_https'] ) echo 's'; ?>://<?php echo htmlspecialchars($_SERVER['HTTP_HOST']); 
						echo contentPath; ?><input type="text" name="page" value="<?php echo htmlspecialchars($info['page']); ?>" />
					</td>
				</tr>
				<tr>
					<td class="row2">
						The problem:
					</td>
					<td class="row2">
						<textarea name="comment" rows="10" cols="40"><?php echo htmlspecialchars($info['comment']); ?></textarea>
					</td>
				</tr>
				<tr>
					<td class="row1">
						Code from image:
					</td>
					<td class="row1">
						<img alt="CAPTCHA" src="<?php echo makeUrlNS('Special', "Captcha/$captchacode"); ?>" style="cursor: pointer;" onclick="this.src = makeUrlNS('Special', 'Captcha/<?php echo $captchacode; ?>', String(Math.floor(Math.random() * 1000000)));" /><br />
						<br />
						Code: <input name="captcha_response" type="text" size="9" /><br />
						<small>If you can't read it, click on the image to get a different one.</small>
						<input type="hidden" name="captcha_id" value="<?php echo $captchacode; ?>" />
					</td>
				</tr>
				<tr>
					<th class="subhead" colspan="2">
						<input type="submit" name="submit" value="Send report" />
					</th>
				</tr>
			</table>
		</div>
	</form>
	<?php
	
	$output->footer();
}

function arp_send_mail($to, $subject, $body)
{
	global $session;
	global $lang, $enano_config;
	
	$use_smtp = getConfig('smtp_enabled') == '1';
		
	//
	// Let's do some checking to make sure that mass mail functions
	// are working in win32 versions of php. (copied from phpBB)
	//
	if ( preg_match('/[c-z]:\\\.*/i', getenv('PATH')) && !$use_smtp)
	{
		$ini_val = ( @phpversion() >= '4.0.0' ) ? 'ini_get' : 'get_cfg_var';

		// We are running on windows, force delivery to use our smtp functions
		// since php's are broken by default
		$use_smtp = true;
		$enano_config['smtp_server'] = @$ini_val('SMTP');
	}
	
	$mail = new emailer( !empty($use_smtp) );
	
	// Validate subject/message body
	$subject = stripslashes(trim($subject));
	$message = stripslashes(trim($body));
	
	if ( empty($subject) )
		$errors[] = $lang->get('acpmm_err_need_subject');
	if ( empty($message) )
		$errors[] = $lang->get('acpmm_err_need_message');
	
	if ( sizeof($errors) < 1 )
	{
	
		$mail->from(getConfig('contact_email'));
		$mail->replyto(getConfig('contact_email'));
		$mail->set_subject($subject);
		$mail->email_address($to);
		
		// Copied/modified from phpBB
		$email_headers = 'X-AntiAbuse: Website server name - ' . $_SERVER['SERVER_NAME'] . "\n";
		$email_headers .= 'X-AntiAbuse: User_id - ' . $session->user_id . "\n";
		$email_headers .= 'X-AntiAbuse: Username - ' . $session->username . "\n";
		$email_headers .= 'X-AntiAbuse: User IP - ' . $_SERVER['REMOTE_ADDR'] . "\n";
		
		$mail->extra_headers($email_headers);
		$mail->use_template($message);
		
		// All done
		$mail->send();
		$mail->reset();
		
		return true;
	}
	
	return $errors;
}
