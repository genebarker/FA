<?php
/**********************************************************************
	Copyright (C) FrontAccounting, LLC.
	Released under the terms of the GNU General Public License, GPL,
	as published by the Free Software Foundation, either version 3
	of the License, or (at your option) any later version.
	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
	See the License here <http://www.gnu.org/licenses/gpl-3.0.html>.
***********************************************************************/
$page_security = 'SA_SUPPLIERANALYTIC';
// ----------------------------------------------------------------
// $ Revision:	2.0 $
// Creator:	Joe Hunt
// date_:	2005-05-19
// Title:	Supplier Balances
// ----------------------------------------------------------------
$path_to_root="..";

include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/data_checks.inc");
include_once($path_to_root . "/gl/includes/gl_db.inc");

//----------------------------------------------------------------------------------------------------

print_supplier_balances();

function get_open_balance($supplier_id, $to)
{
	$to = date2sql($to);

	$sql = "SELECT SUM(IF(".TB_PREF."supp_trans.type = ".ST_SUPPINVOICE." OR ".TB_PREF."supp_trans.type = ".ST_BANKDEPOSIT.", 
		(".TB_PREF."supp_trans.ov_amount + ".TB_PREF."supp_trans.ov_gst + ".TB_PREF."supp_trans.ov_discount), 0)) AS charges,
		SUM(IF(".TB_PREF."supp_trans.type <> ".ST_SUPPINVOICE." AND ".TB_PREF."supp_trans.type <> ".ST_BANKDEPOSIT.", 
		(".TB_PREF."supp_trans.ov_amount + ".TB_PREF."supp_trans.ov_gst + ".TB_PREF."supp_trans.ov_discount), 0)) AS credits,
		SUM(".TB_PREF."supp_trans.alloc) AS Allocated,
		SUM(IF(".TB_PREF."supp_trans.type = ".ST_SUPPINVOICE." OR ".TB_PREF."supp_trans.type = ".ST_BANKDEPOSIT.",
		(".TB_PREF."supp_trans.ov_amount + ".TB_PREF."supp_trans.ov_gst + ".TB_PREF."supp_trans.ov_discount - ".TB_PREF."supp_trans.alloc),
		(".TB_PREF."supp_trans.ov_amount + ".TB_PREF."supp_trans.ov_gst + ".TB_PREF."supp_trans.ov_discount + ".TB_PREF."supp_trans.alloc))) AS OutStanding
		FROM ".TB_PREF."supp_trans
		WHERE ".TB_PREF."supp_trans.tran_date < '$to'
		AND ".TB_PREF."supp_trans.supplier_id = '$supplier_id' GROUP BY supplier_id";

	$result = db_query($sql,"No transactions were returned");
	return db_fetch($result);
}

function getTransactions($supplier_id, $from, $to)
{
	$from = date2sql($from);
	$to = date2sql($to);

	$sql = "SELECT st.*,
				(st.ov_amount + st.ov_gst + st.ov_discount)
				AS TotalAmount, st.alloc AS Allocated,
				((st.type = ".ST_SUPPINVOICE.")
					AND st.due_date < '$to') AS OverDue,
				bt.bank_act, bt.cheque_no, bt.tt_ind,
				ba.account_type as bank_account_type
			FROM ".TB_PREF."supp_trans st
			LEFT OUTER JOIN ".TB_PREF."bank_trans bt
			ON st.type = bt.type AND st.trans_no = bt.trans_no
			LEFT OUTER JOIN ".TB_PREF."bank_accounts ba
			ON bt.bank_act = ba.id
			WHERE st.tran_date >= '$from' AND st.tran_date <= '$to' 
			AND st.supplier_id = '$supplier_id' AND st.ov_amount!=0
			ORDER BY st.tran_date";

	$TransResult = db_query($sql,"No transactions were returned");

	return $TransResult;
}

//----------------------------------------------------------------------------------------------------

function print_supplier_balances()
{
	global $path_to_root, $systypes_array, $print_invoice_no;

	$from = $_POST['PARAM_0'];
	$to = $_POST['PARAM_1'];
	$fromsupp = $_POST['PARAM_2'];
	$show_allocation = $_POST['PARAM_3'];
	$show_balance = $_POST['PARAM_4'] ? false : true;
	$currency = $_POST['PARAM_5'];
	$no_zeros = $_POST['PARAM_6'];
	$comments = $_POST['PARAM_7'];
	$orientation = $_POST['PARAM_8'];
	$destination = $_POST['PARAM_9'];
	if ($destination)
		include_once($path_to_root . "/reporting/includes/excel_report.inc");
	else
		include_once($path_to_root . "/reporting/includes/pdf_report.inc");

	$orientation = ($orientation ? 'L' : 'P');
	if ($fromsupp == ALL_TEXT)
		$supp = _('All');
	else
		$supp = get_supplier_name($fromsupp);

	$dec = user_price_dec();

	if ($currency == ALL_TEXT)
	{
		$convert = true;
		$currency = _('Balances in Home currency');
	}
	else
		$convert = false;

	if ($no_zeros) $nozeros = _('Yes');
	else $nozeros = _('No');

	// display regular values in balance column as positive numbers
	$sign = -1;

	$cols = array(0, 75, 135, 185, 235, 295, 355, 405, 465, 515);

	$allocation_heading = $show_allocation ? _('Allocated') : _(' ');
	$balance_heading = $show_balance ? _('Balance') : _('Outstanding');
	$headers = array(_('Trans Type'), _('Detail'), _('#'), _('Date'),
		_('Due Date'), _('Charges'), _('Credits'), $allocation_heading,
		$balance_heading);

	$aligns = array('left', 'left', 'left', 'left', 'left', 'right',
		'right', 'right', 'right');

	$params = array(0 => $comments,
		1 => array('text' => _('Period'), 'from' => $from, 'to' => $to),
		2 => array('text' => _('Supplier'), 'from' => $supp, 'to' => ''),
		3 => array('text' => _('Currency'),'from' => $currency, 'to' => ''),
		4 => array('text' => _('Suppress Zeros'), 'from' => $nozeros, 'to' => ''));

	$rep = new FrontReport(_('Supplier Balances'), "SupplierBalances", user_pagesize(), 9, $orientation);
	if ($orientation == 'L')
		recalculate_cols($cols);

	$rep->Font();
	$rep->Info($params, $cols, $headers, $aligns);
	$rep->NewPage();

	$total = array();
	$grandtotal = array(0,0,0,0);

	$sql = "SELECT supplier_id, supp_name AS name, curr_code FROM ".TB_PREF."suppliers";
	if ($fromsupp != ALL_TEXT)
		$sql .= " WHERE supplier_id=".db_escape($fromsupp);
	$sql .= " ORDER BY supp_name";
	$result = db_query($sql, "The customers could not be retrieved");

	while ($myrow=db_fetch($result))
	{
		if (!$convert && $currency != $myrow['curr_code'])
			continue;
		$accumulate = 0;
		$rate = $convert ? get_exchange_rate_from_home_currency($myrow['curr_code'], Today()) : 1;
		$bal = get_open_balance($myrow['supplier_id'], $from);
		$init[0] = $init[1] = 0.0;
		$init[0] = round2(abs($bal['charges']*$rate), $dec);
		$init[1] = round2(Abs($bal['credits']*$rate), $dec);
		$init[2] = round2($bal['Allocated']*$rate, $dec);
		if ($show_balance)
		{
			$init[3] = $init[0] - $init[1];
			$accumulate += $init[3];
		}	
		else	
			$init[3] = round2($bal['OutStanding']*$rate, $dec);
		$res = getTransactions($myrow['supplier_id'], $from, $to);
		if ($no_zeros && db_num_rows($res) == 0) continue;

		$rep->fontSize += 2;
		$rep->TextCol(0, 3, $myrow['name']);
		if ($convert) $rep->TextCol(3, 4, $myrow['curr_code']);
		$rep->fontSize -= 2;
		$rep->TextCol(4, 5,	_("Open Balance"));
		$rep->AmountCol(5, 6, $init[0], $dec);
		$rep->AmountCol(6, 7, $init[1], $dec);
		if ($show_allocation)
			$rep->AmountCol(7, 8, $init[2], $dec);
		$rep->AmountCol(8, 9, $sign*$init[3], $dec);
		$total = array(0,0,0,0);
		for ($i = 0; $i < 4; $i++)
		{
			$total[$i] += $init[$i];
			$grandtotal[$i] += $init[$i];
		}
		$rep->NewLine(1, 2);
		$rep->Line($rep->row + 4);
		if (db_num_rows($res)==0) {
			$rep->NewLine(1, 2);
			continue;
		}	
		while ($trans=db_fetch($res))
		{
			if ($no_zeros && floatcmp(abs($trans['TotalAmount']), $trans['Allocated']) == 0) continue;
			$rep->NewLine(1, 2);
			$rep->TextCol(0, 1, $systypes_array[$trans['type']]);
			$rep->TextCol(1, 2, get_bank_trans_type_detail_view_str($trans["type"], $trans['bank_account_type'], $trans["cheque_no"], $trans["tt_ind"]));
			$rep->TextCol(2, 3, $print_invoice_no ? $trans['trans_no'] : $trans['reference']);
			$rep->DateCol(3, 4, $trans['tran_date'], true);
			if ($trans['type'] == ST_SUPPINVOICE)
				$rep->DateCol(4, 5,	$trans['due_date'], true);
			$item[0] = $item[1] = 0.0;
			if ($trans['TotalAmount'] > 0.0)
			{
				$item[0] = round2(abs($trans['TotalAmount']) * $rate, $dec);
				$rep->AmountCol(5, 6, $item[0], $dec);
				$accumulate += $item[0];
			}
			else
			{
				$item[1] = round2(abs($trans['TotalAmount']) * $rate, $dec);
				$rep->AmountCol(6, 7, $item[1], $dec);
				$accumulate -= $item[1];
			}
			$item[2] = round2($trans['Allocated'] * $rate, $dec);
			if ($show_allocation)
				$rep->AmountCol(7, 8, $item[2], $dec);
			if ($trans['TotalAmount'] > 0.0)
				$item[3] = $item[0] - $item[2];
			else	
				$item[3] = ($item[1] - $item[2]) * -1;
			if ($show_balance)
				$rep->AmountCol(8, 9, $sign*$accumulate, $dec);
			else	
				$rep->AmountCol(8, 9, $sign*$item[3], $dec);
			for ($i = 0; $i < 4; $i++)
			{
				$total[$i] += $item[$i];
				$grandtotal[$i] += $item[$i];
			}
			if ($show_balance)
				$total[3] = $total[0] - $total[1];
		}
		$rep->Line($rep->row - 8);
		$rep->NewLine(2);
		$rep->TextCol(0, 3,	_('Total'));
		for ($i = 0; $i < 4; $i++)
		{
			if ($i == 2 && !$show_allocation) continue;
			$rep->AmountCol($i + 5, $i + 6, $i==3 ? $sign*$total[$i] : $total[$i], $dec);
		}
		$rep->Line($rep->row  - 4);
		$rep->NewLine(2);
	}
	$rep->fontSize += 2;
	$rep->TextCol(0, 3,	_('Grand Total'));
	$rep->fontSize -= 2;
	if ($show_balance)
		$grandtotal[3] = $grandtotal[0] - $grandtotal[1];
	for ($i = 0; $i < 4; $i++) {
		if ($i == 2 && !$show_allocation) continue;
		$rep->AmountCol($i + 5, $i + 6, $i == 3 ? $sign * $grandtotal[$i] : $grandtotal[$i], $dec);
	}
	$rep->Line($rep->row  - 4);
	$rep->NewLine();
	$rep->End();
}

?>
