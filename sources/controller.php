<?php

function moulinette_get($var) {
  return htmlspecialchars(exec('sudo yunohost app setting vpnclient '.escapeshellarg($var)));
}

function moulinette_set($var, $value) {
  return exec('sudo yunohost app setting vpnclient '.escapeshellarg($var).' -v '.escapeshellarg($value));
}

function stop_service() {
  exec('sudo service ynh-vpnclient litestop');
}

function start_service() {
  exec('sudo service ynh-vpnclient start', $output, $retcode);

  return $retcode;
}

function ipv6_expanded($ip) {
  exec('ipv6_expanded '.escapeshellarg($ip), $output);

  return $output[0];
}

function ipv6_compressed($ip) {
  exec('ipv6_compressed '.escapeshellarg($ip), $output);

  return $output[0];
}

dispatch('/', function() {
  $ip6_net = moulinette_get('ip6_net');
  $ip6_net = ($ip6_net == 'none') ? '' : $ip6_net;

  set('server_name', moulinette_get('server_name'));
  set('server_port', moulinette_get('server_port'));
  set('server_proto', moulinette_get('server_proto'));
  set('login_user', moulinette_get('login_user'));
  set('login_passphrase', moulinette_get('login_passphrase'));
  set('ip6_net', $ip6_net);
  set('crt_client_exists', file_exists('/etc/openvpn/keys/user.crt'));
  set('crt_client_key_exists', file_exists('/etc/openvpn/keys/user.key'));
  set('crt_server_ca_exists', file_exists('/etc/openvpn/keys/ca-server.crt'));

  return render('settings.html.php');
});

dispatch_put('/settings', function() {
  $crt_client_exists = file_exists('/etc/openvpn/keys/user.crt');
  $crt_client_key_exists = file_exists('/etc/openvpn/keys/user.key');
  $crt_server_ca_exists = file_exists('/etc/openvpn/keys/ca-server.crt');

  $ip6_net = empty($_POST['ip6_net']) ? 'none' : $_POST['ip6_net'];
  $ip6_addr = 'none';

  if(empty($_POST['server_name']) || empty($_POST['server_port']) || empty($_POST['server_proto'])) {
    flash('error', T_('The Server Address, the Server Port and the Protocol cannot be empty.'));
    goto redirect;
  }

  if(!preg_match('/^\d+$/', $_POST['server_port'])) {
    flash('error', T_('The Server Port must be only composed of digits.'));
    goto redirect;
  }

  if($_POST['server_proto'] != 'udp' && $_POST['server_proto'] != 'tcp') {
    flash('error', T_('The Protocol must be "udp" or "tcp".'));
    goto redirect;
  }

  if(($_FILES['crt_client']['error'] == UPLOAD_ERR_OK && $_FILES['crt_client_key']['error'] != UPLOAD_ERR_OK && (!$crt_client_key_exists || $_POST['crt_client_key_delete'] == 1))
    || ($_FILES['crt_client_key']['error'] == UPLOAD_ERR_OK && $_FILES['crt_client']['error'] != UPLOAD_ERR_OK && (!$crt_client_exists || $_POST['crt_client_delete'] == 1))) {

    flash('error', T_('A Client Certificate is needed when you suggest a Key (or vice versa).'));
    goto redirect;
  }

  if(empty($_POST['login_user']) xor empty($_POST['login_passphrase'])) {
    flash('error', T_('A Password is needed when you suggest a Username (or vice versa).'));
    goto redirect;
  }

  if($_FILES['crt_server_ca']['error'] != UPLOAD_ERR_OK && !$crt_server_ca_exists) {
    flash('error', T_('You need a Server CA.'));
    goto redirect;
  }

  if(($_FILES['crt_client_key']['error'] != UPLOAD_ERR_OK && (!$crt_client_key_exists || $_POST['crt_client_key_delete'] == 1)) && empty($_POST['login_user'])) {
    flash('error', T_('You need either a Client Certificate, either a Username (or both).'));
    goto redirect;
  }

  if($ip6_net != 'none') {
    $ip6_net = ipv6_expanded($ip6_net);

    if(empty($ip6_net)) {
      flash('error', T_('The IPv6 Delegated Prefix format looks bad.'));
      goto redirect;
    }

    $ip6_blocs = explode(':', $ip6_net);
    $ip6_addr = "${ip6_blocs[0]}:${ip6_blocs[1]}:${ip6_blocs[2]}:${ip6_blocs[3]}:${ip6_blocs[4]}:${ip6_blocs[5]}:${ip6_blocs[6]}:1";

    $ip6_net = ipv6_compressed($ip6_net);
    $ip6_addr = ipv6_compressed($ip6_addr);
  }

  stop_service();

  moulinette_set('server_name', $_POST['server_name']);
  moulinette_set('server_port', $_POST['server_port']);
  moulinette_set('server_proto', $_POST['server_proto']);
  moulinette_set('login_user', $_POST['login_user']);
  moulinette_set('login_passphrase', $_POST['login_passphrase']);
  moulinette_set('ip6_net', $ip6_net);
  moulinette_set('ip6_addr', $ip6_addr);

  if($_FILES['crt_client']['error'] == UPLOAD_ERR_OK) {
    move_uploaded_file($_FILES['crt_client']['tmp_name'], '/etc/openvpn/keys/user.crt');
  } elseif($_POST['crt_client_delete'] == 1) {
    unlink('/etc/openvpn/keys/user.crt');
  }

  if($_FILES['crt_client_key']['error'] == UPLOAD_ERR_OK) {
    move_uploaded_file($_FILES['crt_client_key']['tmp_name'], '/etc/openvpn/keys/user.key');
  } elseif($_POST['crt_client_key_delete'] == 1) {
    unlink('/etc/openvpn/keys/user.key');
  }

  if($_FILES['crt_server_ca']['error'] == UPLOAD_ERR_OK) {
    move_uploaded_file($_FILES['crt_server_ca']['tmp_name'], '/etc/openvpn/keys/ca-server.crt');
  }

  if(!empty($_POST['login_user'])) {
    file_put_contents('/etc/openvpn/keys/credentials', "${_POST['login_user']}\n${_POST['login_passphrase']}");
  } else {
    file_put_contents('/etc/openvpn/keys/credentials', '');
  }

  $retcode = start_service();

  if($retcode == 0) {
    flash('success', T_('Configuration updated and service successfully reloaded'));
  } else {
    flash('error', T_('Configuration updated but service reload failed'));
  }

  redirect:
  redirect_to('/');
});

dispatch('/lang/:locale', function($locale = 'en') {
  switch ($locale) {
    case 'fr':
      $_SESSION['locale'] = 'fr';
      break;

    default:
      $_SESSION['locale'] = 'en';
  }

  if(!empty($_GET['redirect_to'])) {
    redirect_to($_GET['redirect_to']);
  } else {
    redirect_to('/');
  }
});