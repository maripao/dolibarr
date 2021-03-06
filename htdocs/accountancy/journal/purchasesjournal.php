<?php
/* Copyright (C) 2007-2010	Laurent Destailleur	<eldy@users.sourceforge.net>
 * Copyright (C) 2007-2010	Jean Heimburger		<jean@tiaris.info>
 * Copyright (C) 2011		Juanjo Menent		<jmenent@2byte.es>
 * Copyright (C) 2012		Regis Houssin		<regis@dolibarr.fr>
 * Copyright (C) 2013-2014  Alexandre Spangaro	<alexandre.spangaro@gmail.com>
 * Copyright (C) 2013-2014  Olivier Geffroy		<jeff@jeffinfo.com>
 * Copyright (C) 2013-2014  Florian Henry	    <florian.henry@open-concept.pro>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * \file		htdocs/accountancy/journal/purchasesjournal.php
 * \ingroup		Accounting Expert
 * \brief		Page with purchases journal
 */

// Dolibarr environment
$res = @include ("../main.inc.php");
if (! $res && file_exists("../main.inc.php"))
	$res = @include ("../main.inc.php");
if (! $res && file_exists("../../main.inc.php"))
	$res = @include ("../../main.inc.php");
if (! $res && file_exists("../../../main.inc.php"))
	$res = @include ("../../../main.inc.php");
if (! $res)
	die("Include of main fails");
	
// Class
dol_include_once("/core/lib/report.lib.php");
dol_include_once("/core/lib/date.lib.php");
require_once DOL_DOCUMENT_ROOT.'/core/lib/accounting.lib.php';
dol_include_once("/fourn/class/fournisseur.facture.class.php");
dol_include_once("/fourn/class/fournisseur.class.php");
dol_include_once("/accountancy/class/bookkeeping.class.php");
dol_include_once("/accountancy/class/accountingaccount.class.php");

// Langs
$langs->load("compta");
$langs->load("bills");
$langs->load("other");
$langs->load("main");
$langs->load("accountancy");

$date_startmonth = GETPOST('date_startmonth');
$date_startday = GETPOST('date_startday');
$date_startyear = GETPOST('date_startyear');
$date_endmonth = GETPOST('date_endmonth');
$date_endday = GETPOST('date_endday');
$date_endyear = GETPOST('date_endyear');

// Security check
if ($user->societe_id > 0)
	accessforbidden();
if (! $user->rights->accounting->access)
	accessforbidden();

$action = GETPOST('action');

/*
 * View
 */

$year_current = strftime("%Y", dol_now());
$pastmonth = strftime("%m", dol_now()) - 1;
$pastmonthyear = $year_current;
if ($pastmonth == 0) {
	$pastmonth = 12;
	$pastmonthyear --;
}

$date_start = dol_mktime(0, 0, 0, $date_startmonth, $date_startday, $date_startyear);
$date_end = dol_mktime(23, 59, 59, $date_endmonth, $date_endday, $date_endyear);

if (empty($date_start) || empty($date_end)) // We define date_start and date_end
{
	$date_start = dol_get_first_day($pastmonthyear, $pastmonth, false);
	$date_end = dol_get_last_day($pastmonthyear, $pastmonth, false);
}

$p = explode(":", $conf->global->MAIN_INFO_SOCIETE_COUNTRY);
$idpays = $p[0];

$sql = "SELECT f.rowid, f.ref, f.type, f.datef as df, f.libelle,";
$sql .= " fd.rowid as fdid, fd.description, fd.total_ttc, fd.tva_tx, fd.total_ht, fd.tva as total_tva, fd.product_type,";
$sql .= " s.rowid as socid, s.nom as name, s.code_compta_fournisseur, s.fournisseur,";
$sql .= " s.code_compta_fournisseur, p.accountancy_code_buy , ct.accountancy_code_buy as account_tva, aa.rowid as fk_compte, aa.account_number as compte, aa.label as label_compte";
$sql .= " FROM " . MAIN_DB_PREFIX . "facture_fourn_det fd";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_tva ct ON fd.tva_tx = ct.taux AND ct.fk_pays = '" . $idpays . "'";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = fd.fk_product";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "accountingaccount aa ON aa.rowid = fd.fk_code_ventilation";
$sql .= " JOIN " . MAIN_DB_PREFIX . "facture_fourn f ON f.rowid = fd.fk_facture_fourn";
$sql .= " JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = f.fk_soc";
$sql .= " WHERE f.fk_statut > 0 ";
if (! empty($conf->multicompany->enabled)) {
	$sql .= " AND f.entity = " . $conf->entity;
}
if (! empty($conf->global->FACTURE_DEPOSITS_ARE_JUST_PAYMENTS))
	$sql .= " AND f.type IN (0,1,2)";
else
	$sql .= " AND f.type IN (0,1,2,3)";
if ($date_start && $date_end)
	$sql .= " AND f.datef >= '" . $db->idate($date_start) . "' AND f.datef <= '" . $db->idate($date_end) . "'";
$sql .= " ORDER BY f.datef";

dol_syslog('accountancy/journal/purchasesjournal.php:: $sql=' . $sql);
$result = $db->query($sql);
if ($result) {
	$num = $db->num_rows($result);
	// les variables
	$cptfour = (! empty($conf->global->COMPTA_ACCOUNT_SUPPLIER)) ? $conf->global->COMPTA_ACCOUNT_SUPPLIER : $langs->trans("CodeNotDef");
	$cpttva = (! empty($conf->global->COMPTA_VAT_ACCOUNT)) ? $conf->global->COMPTA_VAT_ACCOUNT : $langs->trans("CodeNotDef");
	
	$tabfac = array ();
	$tabht = array ();
	$tabtva = array ();
	$tabttc = array ();
	$tabcompany = array ();
	
	$i = 0;
	while ( $i < $num ) {
		$obj = $db->fetch_object($result);
		// contrôles
		$compta_soc = (! empty($obj->code_compta_fournisseur)) ? $obj->code_compta_fournisseur : $cptfour;
		$compta_prod = $obj->compte;
		if (empty($compta_prod)) {
			if ($obj->product_type == 0)
				$compta_prod = (! empty($conf->global->COMPTA_PRODUCT_BUY_ACCOUNT)) ? $conf->global->COMPTA_PRODUCT_BUY_ACCOUNT : $langs->trans("CodeNotDef");
			else
				$compta_prod = (! empty($conf->global->COMPTA_SERVICE_BUY_ACCOUNT)) ? $conf->global->COMPTA_SERVICE_BUY_ACCOUNT : $langs->trans("CodeNotDef");
		}
		$compta_tva = (! empty($obj->account_tva) ? $obj->account_tva : $cpttva);
		
		$tabfac[$obj->rowid]["date"] = $obj->df;
		$tabfac[$obj->rowid]["ref"] = $obj->ref;
		$tabfac[$obj->rowid]["type"] = $obj->type;
		$tabfac[$obj->rowid]["description"] = $obj->description;
		$tabfac[$obj->rowid]["fk_facturefourndet"] = $obj->fdid;
		$tabttc[$obj->rowid][$compta_soc] += $obj->total_ttc;
		$tabht[$obj->rowid][$compta_prod] += $obj->total_ht;
		$tabtva[$obj->rowid][$compta_tva] += $obj->total_tva;
		$tabcompany[$obj->rowid] = array (
				'id' => $obj->socid,
				'name' => $obj->name,
				'code_fournisseur' => $obj->code_compta_fournisseur 
		);
		
		$i ++;
	}
} else {
	dol_print_error($db);
}

/*
 * Actions
*/
// Bookkeeping Write
if ($action == 'writebookkeeping') {
	$now = dol_now();
	
	foreach ( $tabfac as $key => $val ) {
		foreach ( $tabttc[$key] as $k => $mt ) {
			// get compte id and label
			
			$bookkeeping = new BookKeeping($db);
			$bookkeeping->doc_date = $val["date"];
			$bookkeeping->doc_ref = $val["ref"];
			$bookkeeping->date_create = $now;
			$bookkeeping->doc_type = 'supplier_invoice';
			$bookkeeping->fk_doc = $key;
			$bookkeeping->fk_docdet = $val["fk_facturefourndet"];
			$bookkeeping->code_tiers = $tabcompany[$key]['code_fournisseur'];
			$bookkeeping->label_compte = $tabcompany[$key]['name'];
			$bookkeeping->numero_compte = $conf->global->COMPTA_ACCOUNT_SUPPLIER;
			$bookkeeping->montant = $mt;
			$bookkeeping->sens = ($mt >= 0) ? 'C' : 'D';
			$bookkeeping->debit = ($mt <= 0) ? $mt : 0;
			$bookkeeping->credit = ($mt > 0) ? $mt : 0;
			$bookkeeping->code_journal = $conf->global->ACCOUNTING_PURCHASE_JOURNAL;
			
			$bookkeeping->create();
		}
		
		// Product / Service
		foreach ( $tabht[$key] as $k => $mt ) {
			if ($mt) {
				// get compte id and label
				$compte = new AccountingAccount($db);
				if ($compte->fetch(null, $k)) {
					$bookkeeping = new BookKeeping($db);
					$bookkeeping->doc_date = $val["date"];
					$bookkeeping->doc_ref = $val["ref"];
					$bookkeeping->date_create = $now;
					$bookkeeping->doc_type = 'supplier_invoice';
					$bookkeeping->fk_doc = $key;
					$bookkeeping->fk_docdet = $val["fk_facturefourndet"];
					$bookkeeping->code_tiers = '';
					$bookkeeping->label_compte = dol_trunc($val["description"], 128);
					$bookkeeping->numero_compte = $k;
					$bookkeeping->montant = $mt;
					$bookkeeping->sens = ($mt < 0) ? 'C' : 'D';
					$bookkeeping->debit = ($mt > 0) ? $mt : 0;
					$bookkeeping->credit = ($mt <= 0) ? $mt : 0;
					$bookkeeping->code_journal = $conf->global->ACCOUNTING_PURCHASE_JOURNAL;
					
					$bookkeeping->create();
				}
			}
		}
		
		// VAT
		// var_dump($tabtva);
		foreach ( $tabtva[$key] as $k => $mt ) {
			if ($mt) {
				// get compte id and label
				
				$bookkeeping = new BookKeeping($db);
				$bookkeeping->doc_date = $val["date"];
				$bookkeeping->doc_ref = $val["ref"];
				$bookkeeping->date_create = $now;
				$bookkeeping->doc_type = 'supplier_invoice';
				$bookkeeping->fk_doc = $key;
				$bookkeeping->fk_docdet = $val["fk_facturefourndet"];
				$bookkeeping->code_tiers = '';
				$bookkeeping->label_compte = $langs->trans("VAT");
				$bookkeeping->numero_compte = $k;
				$bookkeeping->montant = $mt;
				$bookkeeping->sens = ($mt < 0) ? 'C' : 'D';
				$bookkeeping->debit = ($mt > 0) ? $mt : 0;
				$bookkeeping->credit = ($mt <= 0) ? $mt : 0;
				$bookkeeping->code_journal = $conf->global->ACCOUNTING_PURCHASE_JOURNAL;
				
				$bookkeeping->create();
			}
		}
	}
}

// export csv

if ($action == 'export_csv') {
	$sep = $conf->global->ACCOUNTING_SEPARATORCSV;
	
	header('Content-Type: text/csv');
	header('Content-Disposition: attachment;filename=journal_achats.csv');
	
	if ($conf->global->ACCOUNTING_MODELCSV == 1) 	// Modèle Export Cegid Expert
	{
		foreach ( $tabfac as $key => $val ) {
			$date = dol_print_date($db->jdate($val["date"]), '%d%m%Y');
			
			// Product / Service
			foreach ( $tabht[$key] as $k => $mt ) {
				$companystatic->id = $tabcompany[$key]['id'];
				$companystatic->name = $tabcompany[$key]['name'];
				$companystatic->client = $tabcompany[$key]['code_client'];
				
				if ($mt) {
					print $date . $sep;
					print $conf->global->ACCOUNTING_PURCHASE_JOURNAL . $sep;
					print length_accountg(html_entity_decode($k)) . $sep;
					print $sep;
					print ($mt < 0 ? 'C' : 'D') . $sep;
					print ($mt <= 0 ? price(- $mt) : $mt) . $sep;
					print dol_trunc($val["description"], 32) . $sep;
					print $val["ref"];
					print "\n";
				}
			}
			
			// VAT
			// var_dump($tabtva);
			foreach ( $tabtva[$key] as $k => $mt ) {
				if ($mt) {
					print $date . $sep;
					print $conf->global->ACCOUNTING_PURCHASE_JOURNAL . $sep;
					print length_accountg(html_entity_decode($k)) . $sep;
					print $sep;
					print ($mt < 0 ? 'C' : 'D') . $sep;
					print ($mt <= 0 ? price(- $mt) : $mt) . $sep;
					print $langs->trans("VAT") . $sep;
					print $val["ref"];
					print "\n";
				}
			}
			print $date . $sep;
			print $conf->global->ACCOUNTING_PURCHASE_JOURNAL . $sep;
			print length_accountg($conf->global->COMPTA_ACCOUNT_SUPPLIER) . $sep;
			
			foreach ( $tabttc[$key] as $k => $mt ) {
				print length_accounta(html_entity_decode($k)) . $sep;
				print ($mt < 0 ? 'D' : 'C') . $sep;
				print ($mt <= 0 ? price(- $mt) : $mt) . $sep;
				print utf8_decode($companystatic->name) . $sep;
				print $val["ref"];
			}
			print "\n";
		}
	} else 	// Modèle Export Classique
	{
		foreach ( $tabfac as $key => $val ) {
			$date = dol_print_date($db->jdate($val["date"]), 'day');
			
			$companystatic->id = $tabcompany[$key]['id'];
			$companystatic->name = $tabcompany[$key]['name'];
			$companystatic->client = $tabcompany[$key]['code_client'];
			
			// Product / Service
			foreach ( $tabht[$key] as $k => $mt ) {
				if ($mt) {
					print '"' . $date . '"' . $sep;
					print '"' . $val["ref"] . '"' . $sep;
					print '"' . length_accountg(html_entity_decode($k)) . '"' . $sep;
					print '"' . dol_trunc($val["description"], 32) . '"' . $sep;
					print '"' . ($mt >= 0 ? price($mt) : '') . '"' . $sep;
					print '"' . ($mt < 0 ? price(- $mt) : '') . '"';
					print "\n";
				}
			}
			// VAT
			// var_dump($tabtva);
			foreach ( $tabtva[$key] as $k => $mt ) {
				if ($mt) {
					print '"' . $date . '"' . $sep;
					print '"' . $val["ref"] . '"' . $sep;
					print '"' . length_accountg(html_entity_decode($k)) . '"' . $sep;
					print '"' . $langs->trans("VAT") . '"' . $sep;
					print '"' . ($mt >= 0 ? price($mt) : '') . '"' . $sep;
					print '"' . ($mt < 0 ? price(- $mt) : '') . '"';
					print "\n";
				}
			}
			
			// Third party
			print '"' . $date . '"' . $sep;
			print '"' . $val["ref"] . '"' . $sep;
			foreach ( $tabttc[$key] as $k => $mt ) {
				print '"' . length_accounta(html_entity_decode($k)) . '"' . $sep;
				print '"' . utf8_decode($companystatic->name) . '"' . $sep;
				print '"' . ($mt < 0 ? price(- $mt) : '') . '"' . $sep;
				print '"' . ($mt >= 0 ? price($mt) : '') . '"';
			}
			print "\n";
		}
	}
} else {
	
	llxHeader('', '', '');
	
	$form = new Form($db);
	
	$nom = $langs->trans("PurchasesJournal");
	$nomlink = '';
	$periodlink = '';
	$exportlink = '';
	$builddate = time();
	$description = $langs->trans("DescPurchasesJournal") . '<br>';
	if (! empty($conf->global->FACTURE_DEPOSITS_ARE_JUST_PAYMENTS))
		$description .= $langs->trans("DepositsAreNotIncluded");
	else
		$description .= $langs->trans("DepositsAreIncluded");
	$period = $form->select_date($date_start, 'date_start', 0, 0, 0, '', 1, 0, 1) . ' - ' . $form->select_date($date_end, 'date_end', 0, 0, 0, '', 1, 0, 1);
	report_header($nom, $nomlink, $period, $periodlink, $description, $builddate, $exportlink, array('action' => ''));
	
	print '<input type="button" class="button" style="float: right;" value="Export CSV" onclick="launch_export();" />';
	
	print '<input type="button" class="button" value="' . $langs->trans("WriteBookKeeping") . '" onclick="writebookkeeping();" />';
	
	print '
	<script type="text/javascript">
		function launch_export() {
		    $("div.fiche div.tabBar form input[name=\"action\"]").val("export_csv");
			$("div.fiche div.tabBar form input[type=\"submit\"]").click();
		    $("div.fiche div.tabBar form input[name=\"action\"]").val("");
		}
		function writebookkeeping() {
		    $("div.fiche div.tabBar form input[name=\"action\"]").val("writebookkeeping");
			$("div.fiche div.tabBar form input[type=\"submit\"]").click();
		    $("div.fiche div.tabBar form input[name=\"action\"]").val("");
		}
	</script>';
	
	/*
	 * Show result array
	 */
	print '<br><br>';
	
	$i = 0;
	print "<table class=\"noborder\" width=\"100%\">";
	print "<tr class=\"liste_titre\">";
	// /print "<td>".$langs->trans("JournalNum")."</td>";
	print "<td>" . $langs->trans("Date") . "</td>";
	print "<td>" . $langs->trans("Piece") . ' (' . $langs->trans("InvoiceRef") . ")</td>";
	print "<td>" . $langs->trans("Account") . "</td>";
	print "<t><td>" . $langs->trans("Type") . "</td><td align='right'>" . $langs->trans("Debit") . "</td><td align='right'>" . $langs->trans("Credit") . "</td>";
	print "</tr>\n";
	
	$var = true;
	$r = '';
	
	$invoicestatic = new FactureFournisseur($db);
	$companystatic = new Fournisseur($db);
	
	foreach ( $tabfac as $key => $val ) {
		$invoicestatic->id = $key;
		$invoicestatic->ref = $val["ref"];
		$invoicestatic->type = $val["type"];
		$invoicestatic->description = html_entity_decode(dol_trunc($val["description"], 32));
		
		$date = dol_print_date($db->jdate($val["date"]), 'day');
		
		// Product / Service
		foreach ( $tabht[$key] as $k => $mt ) {
			if ($mt) {
				print "<tr " . $bc[$var] . " >";
				// print "<td>".$conf->global->COMPTA_JOURNAL_BUY."</td>";
				print "<td>" . $date . "</td>";
				print "<td>" . $invoicestatic->getNomUrl(1) . "</td>";
				print "<td>" . length_accountg($k) . "</td>";
				print "<td>" . $invoicestatic->description . "</td>";
				print '<td align="right">' . ($mt >= 0 ? price($mt) : '') . "</td>";
				print '<td align="right">' . ($mt < 0 ? price(- $mt) : '') . "</td>";
				print "</tr>";
			}
		}
		// VAT
		// var_dump($tabtva);
		foreach ( $tabtva[$key] as $k => $mt ) {
			if ($mt) {
				print "<tr " . $bc[$var] . " >";
				// print "<td>".$conf->global->COMPTA_JOURNAL_BUY."</td>";
				print "<td>" . $date . "</td>";
				print "<td>" . $invoicestatic->getNomUrl(1) . "</td>";
				print "<td>" . length_accountg($k) . "</td><td>" . $langs->trans("VAT") . "</td>";
				print '<td align="right">' . ($mt >= 0 ? price($mt) : '') . "</td>";
				print '<td align="right">' . ($mt < 0 ? price(- $mt) : '') . "</td>";
				print "</tr>";
			}
		}
		print "<tr " . $bc[$var] . ">";
		
		// Third party
		// print "<td>".$conf->global->COMPTA_JOURNAL_BUY."</td>";
		print "<td>" . $date . "</td>";
		print "<td>" . $invoicestatic->getNomUrl(1) . "</td>";
		
		foreach ( $tabttc[$key] as $k => $mt ) {
			$companystatic->id = $tabcompany[$key]['id'];
			$companystatic->name = $tabcompany[$key]['name'];
			
			print "<td>" . length_accounta($k);
			print "</td><td>" . $langs->trans("ThirdParty");
			print ' (' . $companystatic->getNomUrl(0, 'supplier', 16) . ')';
			print "</td>";
			print '<td align="right">' . ($mt < 0 ? - price(- $mt) : '') . "</td>";
			print '<td align="right">' . ($mt >= 0 ? price($mt) : '') . "</td>";
		}
		print "</tr>";
		
		$var = ! $var;
	}
	
	print "</table>";
	
	// End of page
	llxFooter();
}
$db->close();