<?php
defined('_VALID_XTC') or die('Direct Access to this location is not allowed.');

$_fpc_menu_name = 'FPC Schaltzentrale';
if (isset($_SESSION['language_code'])) {
    switch ($_SESSION['language_code']) {
        case 'en': $_fpc_menu_name = 'FPC Control Center'; break;
        case 'fr': $_fpc_menu_name = 'Centre de controle FPC'; break;
        case 'es': $_fpc_menu_name = 'Centro de control FPC'; break;
        default:   $_fpc_menu_name = 'FPC Schaltzentrale'; break;
    }
}

if ((defined('MODULE_MRHANF_FPC_STATUS')) && (MODULE_MRHANF_FPC_STATUS == 'True')) {
    $add_contents[BOX_HEADING_STATISTICS][$_fpc_menu_name][] = array(
        'admin_access_name' => 'fpc_dashboard',
        'filename'          => 'fpc_dashboard.php',
        'boxname'           => $_fpc_menu_name,
        'parameters'        => '',
        'ssl'               => '',
    );
}
?>
