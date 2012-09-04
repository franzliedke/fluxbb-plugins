<?php

/**
 * User mass creation plugin for FluxBB
 * 
 * Created by Franz Liedke
 * Plugin sponsored by ximios.com
 */

// Make sure no one attempts to run this script "directly"
if (!defined('PUN'))
	exit;

// Tell admin_loader.php that this is indeed a plugin and that it is loaded
define('PUN_PLUGIN_LOADED', 1);


// Fetch all valid groups
$result = $db->query('SELECT * FROM '.$db->prefix.'groups WHERE g_id NOT IN ('.PUN_GUEST.','.PUN_UNVERIFIED.')') or error('Unable to fetch groups', __FILE__, __LINE__, $db->error());
$all_groups = array();
while ($cur_group = $db->fetch_assoc($result))
{
	$all_groups[$cur_group['g_id']] = $cur_group['g_title'];
}


// Upload a file
if (isset($_POST['process_form']))
{
	if (!isset($_FILES['users_file']))
		message('You did not select a file for upload.');

	$uploaded_file = $_FILES['users_file'];

	// Make sure the upload went smooth
	if (isset($uploaded_file['error']))
	{
		switch ($uploaded_file['error'])
		{
			case 1: // UPLOAD_ERR_INI_SIZE
			case 2: // UPLOAD_ERR_FORM_SIZE
				message('The selected file was too large to upload. The server didn\'t allow the upload.');
				break;

			case 3: // UPLOAD_ERR_PARTIAL
				message('The selected file was only partially uploaded. Please try again.');
				break;

			case 4: // UPLOAD_ERR_NO_FILE
				message('You did not select a file for upload.');
				break;

			case 6: // UPLOAD_ERR_NO_TMP_DIR
				message('PHP was unable to save the uploaded file to a temporary location.');
				break;

			default:
				// No error occured, but was something actually uploaded?
				if ($uploaded_file['size'] == 0)
					message('You did not select a file for upload.');
				break;
		}
	}

	if (is_uploaded_file($uploaded_file['tmp_name']))
	{
		// Preliminary file check, adequate in most cases
		if ($uploaded_file['type'] != 'text/plain')
			message('The file you tried to upload does not seem to be a text file.');

		$extension = pathinfo($uploaded_file['name'], PATHINFO_EXTENSION);
		if ($extension != 'txt')
			message('The file you tried to upload is not a TXT file.');

		// Make sure the file isn't too big
		if ($uploaded_file['size'] > 524288) // 512 KiB
			message('The file you tried to upload is larger than the maximum allowed '.file_size(524288).'.');

		// Move the file to the upload directory
		$file_name = PUN_ROOT.'_process_/'.date('Y-m-d.H.m.s').'.txt';
		if (!@move_uploaded_file($uploaded_file['tmp_name'], $file_name))
			message('The server was unable to save the uploaded file.');

		@chmod($file_name, 0644);
	}
	else
		message('An unknown error occurred. Please try again.');

	// Try to extract the user information from the file
	$new_users = $usernames = $emails = array();
	$errors = array();
	foreach (file($file_name) as $line_number => $line)
	{
		$line = trim($line);

		if (empty($line))
			continue;

		$line_number++;
		if (substr_count($line, ',') != 2)
			message('The file format does not seem to be correct. Error in line: '.$line_number);

		list($full_name, $username, $email) = array_map('trim', explode(',', $line));

		// Validate username, this automatically populates the $errors array
		include_once PUN_ROOT.'lang/'.$pun_user['language'].'/register.php';
		include_once PUN_ROOT.'lang/'.$pun_user['language'].'/prof_reg.php';
		check_username($username);

		// Validate email
		include_once PUN_ROOT.'include/email.php';
		if (!is_valid_email($email))
			$errors[] = 'The email "'.pun_htmlspecialchars($email).'" does not seem to be valid. Error in line: '.$line_number;
		else if (is_banned_email($email))
			$erros[] = 'The email "'.pun_htmlspecialchars($email).'" was banned in the system. Error in line: '.$line_number;

		$new_users[] = compact('full_name', 'username', 'email');
		$usernames[] = $username;
		$emails[] = $email;
	}

	// Make sure we don't try to insert duplicate usernames or emails
	$new_user_count = count($usernames);
	if ($new_user_count == 0)
		message('No new users found in the file.');
	if (count(array_unique($usernames)) < $new_user_count)
		$errors[] = 'The file contains multiple users with the same username.';
	if (count(array_unique($emails)) < $new_user_count)
		$errors[] = 'The file contains multiple users with the same email address.';

	// Make sure the emails don't exist in the database
	$escaped_emails = array();
	foreach ($emails as $email)
		$escaped_emails[] = '\''.$db->escape($email).'\'';
	
	$email_list = '('.implode(',', $escaped_emails).')';
	$result = $db->query('SELECT * FROM '.$db->prefix.'users WHERE email IN '.$email_list) or error('Unable to fetch users with duplicate email addresses');

	if ($db->num_rows($result))
	{
		$dupes = array();
		while ($cur_dupe_user = $db->fetch_assoc($result))
			$dupes[] = $cur_dupe_user['email'];

		$errors[] = 'The file contains the following email addresses that already exist in the database: '.implode(', ', $dupes).'.';
	}

	if (!empty($errors))
	{
		@unlink($file_name);
		message('The following errors occured:'."\n".'<ul><li>'.implode('</li><li>', $errors).'</li></ul>');
	}

	if (isset($_POST['group']) && array_key_exists($_POST['group'], $all_groups))
		$initial_group_id = $_POST['group'];
	else
		$initial_group_id = $pun_config['o_default_user_group'];

	$email_setting = $pun_config['o_default_email_setting'];
	$language = $pun_config['o_default_lang'];

	// And now, insert the users!
	$now = time();
	foreach ($new_users as $cur_user)
	{
		$password = random_key(6, true);
		$password_hash = pun_hash($password);

		// Save them in the database
		$db->query('INSERT INTO '.$db->prefix.'users (username, group_id, password, email, realname, email_setting, language, style, registered, registration_ip, last_visit) VALUES (\''.$db->escape($cur_user['username']).'\', '.$initial_group_id.', \''.$password_hash.'\', \''.$db->escape($cur_user['email']).'\', \''.$db->escape($cur_user['full_name']).'\', '.$email_setting.', \''.$db->escape($language).'\', \''.$pun_config['o_default_style'].'\', '.$now.', \''.get_remote_address().'\', '.$now.')') or error('Unable to create user', __FILE__, __LINE__, $db->error());

		// Send them a notification email so they now they've been registered
		$to = $cur_user['email'];
		$subject = 'Your account information';
		$mail = <<<EOT
Hello {$cur_user['full_name']}, an account was created for you at the forums at {$pun_config['o_base_url']}.

Your username is {$cur_user['username']}.
Your password was set to {$password}.

Please login using this information and change your password immediately.

Have fun using the forums!

---
This is an automatically generated email. Please do not reply.
EOT;

		pun_mail($to, $subject, $mail);
	}

	redirect('admin_loader.php?plugin=AP_Mass_create_users.php', 'Users created successfully. Redirecting...');
}
else if (isset($_GET['file']))
{
	$filename = trim($_GET['file']);
	$filepath = PUN_ROOT.'_process_/'.$filename;

	$all_files = array_map('basename', glob(PUN_ROOT.'_process_/*.txt'));

	if (!in_array($filename, $all_files))
		exit('Bad request');

	if (isset($_GET['view']))
	{
		$contents = file_get_contents($filepath);

		header('Content-Type: text/plain');
		echo $contents;
		exit;
	}
	else if (isset($_GET['download']))
	{
		header('Content-Disposition: attachment; filename="'.$filename.'"');
		header('Content-Type: application/force-download');
		header('Content-Length: '.filesize($filepath));
		header('Connection: close');
		readfile($filepath);
		exit;
	}
	else if (isset($_GET['delete']))
	{
		@unlink($filepath);

		redirect('admin_loader.php?plugin=AP_Mass_create_users.php', 'File deleted successfully. Redirecting...');
	}
}


// Display the admin navigation menu
generate_admin_menu($plugin);

?>

	<div class="blockform">
		<h2><span>Mass Create Users</span></h2>
		<div class="box">
			<form id="mcu" method="post" action="<?php echo $_SERVER['REQUEST_URI'] ?>" enctype="multipart/form-data">
				<div class="inform">
					<fieldset>
						<legend>Add New Users</legend>
						<div class="infldset upload_file">
							<p>
								Provide a .txt file to mass create users in the system. Each line in the .txt file must be formatted as follows: 
								<code>full name,username,email</code>.
							</p>
							<p>
								Example:
<pre>Mary Smith,msmith95,msmith@aol.com
John Kennedy,jkennedy,jkeneddy@aol.com</pre>
							</p>
							<table class="aligntop" cellspacing="0">
								<tr>
									<th scope="row">File</th>
									<td>
										<input type="file" name="users_file" />
									</td>
								</tr>
								<tr>
									<th scope="row">Group</th>
									<td>
										<select name="group">
<?php foreach ($all_groups as $group_id => $group_name) : ?>
											<option value="<?php echo $group_id; ?>"<?php if ($group_id == $pun_config['o_default_user_group']) echo ' selected="selected"' ?>><?php echo pun_htmlspecialchars($group_name); ?></option>
<?php endforeach; ?>
										</select>
									</td>
								</tr>
							</table>
  						</div>
					</fieldset>
				</div>
				<p class="submitend"><input type="submit" name="process_form" value="Process" /></p>
				<div class="inform">
					<fieldset>
						<legend>Processed Files:</legend>
						<div class="infldset">

							<table cellspacing="0" >
							<thead>
								<tr>
									<th class="tcl" scope="col">File Name</th>
									<th class="tc2" scope="col"></th>
									<th class="tc3" scope="col"></th>
									<th class="tc4" scope="col"></th>
								</tr>
							</thead>

							<tbody>

<?php

$all_files = array();
foreach (glob(PUN_ROOT.'_process_/*.txt') as $filename)
{
	$all_files[] = htmlspecialchars(basename($filename));
}

$all_files = array_reverse($all_files);

foreach ($all_files as $filename)
{

?>
								<tr>
									<th class="tcl" scope="col"><?php echo $filename; ?></th>
									<th class="tc2" scope="col"><a href="<?php echo $_SERVER['REQUEST_URI'].'&amp;file='.$filename.'&amp;view' ?>">View</a></th>
									<th class="tc3" scope="col"><a href="<?php echo $_SERVER['REQUEST_URI'].'&amp;file='.$filename.'&amp;download' ?>">Download</a></th>
									<th class="tc4" scope="col"><a href="<?php echo $_SERVER['REQUEST_URI'].'&amp;file='.$filename.'&amp;delete' ?>">Delete</a></th>
								</tr>
<?php

}

?>

							</tbody>
							</table>


						</div>
					</fieldset>
				</div>
			</form>
		</div> 
	</div>
