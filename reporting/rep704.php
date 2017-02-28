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
$page_security = 'SA_GLREP';
// ----------------------------------------------------------------
// $ Revision:	2.0 $
// Creator:	Joe Hunt
// date_:	2005-05-19
// Title:	GL Accounts Transactions
// ----------------------------------------------------------------
$path_to_root="..";

include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/admin/db/fiscalyears_db.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/data_checks.inc");
include_once($path_to_root . "/gl/includes/gl_db.inc");

//----------------------------------------------------------------------------------------------------

print_GL_transactions();

//----------------------------------------------------------------------------------------------------

function get_gl_balance_from_to_with_curr_filter($from_date, $to_date, $account,
                                                 $dimension=0, $dimension2=0, $currency='')
{
    if ($currency=='') // use original
        return get_gl_balance_from_to($from_date, $to_date, $account, $dimension, $dimension2);

    // use enhanced version with currency filter
    $from = date2sql($from_date);
    $to = date2sql($to_date);

    $sql = "SELECT glt.*, COALESCE(ba.bank_curr_code, dtm.curr_code, sp.value) AS curr_code,
            CASE WHEN glt.amount=0 THEN 0 ELSE COALESCE(bt.amount, glt.amount/dt.rate, glt.amount) END AS curr_amount
        FROM ".TB_PREF."gl_trans glt
            LEFT OUTER JOIN ".TB_PREF."bank_accounts ba ON glt.account=ba.account_code
            LEFT OUTER JOIN ".TB_PREF."bank_trans bt ON glt.type_no=bt.trans_no
                AND glt.type=bt.type
                AND bt.bank_act=ba.id
                AND bt.id IN (SELECT MAX(id) FROM ".TB_PREF."bank_trans GROUP BY type, trans_no, bank_act)
            LEFT OUTER JOIN ".TB_PREF."debtor_trans dt ON glt.type_no=dt.trans_no AND glt.type=dt.type
            LEFT OUTER JOIN ".TB_PREF."debtors_master dtm ON dt.debtor_no=dtm.debtor_no,
            ".TB_PREF."sys_prefs sp
		WHERE sp.name = 'curr_default'
		    AND glt.account = '$account'";
    if ($from_date != "")
        $sql .= "  AND glt.tran_date > '$from'";
    if ($to_date != "")
        $sql .= "  AND glt.tran_date < '$to'";
    if ($dimension != 0)
        $sql .= " AND glt.dimension_id = ".($dimension<0?0:db_escape($dimension));
    if ($dimension2 != 0)
        $sql .= " AND glt.dimension2_id = ".($dimension2<0?0:db_escape($dimension2));

    $sql2 = "SELECT SUM(curr_amount) FROM (".$sql.") foo WHERE curr_code = '$currency'";

    error_log($sql2);

    $result = db_query($sql2, "The starting balance for account $account could not be calculated");

    $row = db_fetch_row($result);
    return $row[0];
}

function get_gl_transactions_with_curr_filter($from_date, $to_date, $trans_no=0,
                             $account=null, $dimension=0, $dimension2=0, $currency='')
{
    if ($currency == '') // use original
        return get_gl_transactions($from_date, $to_date, -1, $account, $dimension, $dimension2);

    // use enhanced version with currency filter
    global $show_voided_gl_trans;

    $from = date2sql($from_date);
    $to = date2sql($to_date);

    $sql = "SELECT glt.*, cm.account_name, COALESCE(ba.bank_curr_code, dtm.curr_code, sp.value) AS curr_code,
            CASE WHEN glt.amount=0 THEN 0 ELSE COALESCE(bt.amount, glt.amount/dt.rate, glt.amount) END AS curr_amount
        FROM ".TB_PREF."gl_trans glt
			LEFT JOIN ".TB_PREF."voided v ON glt.type_no=v.id AND glt.type=v.type
			LEFT OUTER JOIN ".TB_PREF."bank_accounts ba ON glt.account=ba.account_code
            LEFT OUTER JOIN ".TB_PREF."bank_trans bt ON glt.type_no=bt.trans_no
                AND glt.type=bt.type
                AND bt.bank_act=ba.id
                AND bt.id IN (SELECT MAX(id) FROM ".TB_PREF."bank_trans GROUP BY type, trans_no, bank_act)
            LEFT OUTER JOIN ".TB_PREF."debtor_trans dt ON glt.type_no=dt.trans_no AND glt.type=dt.type
            LEFT OUTER JOIN ".TB_PREF."debtors_master dtm ON dt.debtor_no=dtm.debtor_no,
            ".TB_PREF."sys_prefs sp,
            ".TB_PREF."chart_master cm
		WHERE sp.name = 'curr_default'
		    AND cm.account_code = glt.account
		    AND glt.account = '$account'
		    AND ISNULL(v.date_)
		    AND glt.tran_date >= '$from'
		    AND glt.tran_date <= '$to'
		    AND COALESCE(ba.bank_curr_code, dtm.curr_code, sp.value) = '$currency'";

    if (isset($show_voided_gl_trans) && $show_voided_gl_trans == 0)
        $sql .= " AND glt.amount <> 0";
    if ($trans_no > 0)
        $sql .= " AND glt.type_no LIKE ".db_escape('%'.$trans_no);

    if ($account != null)
        $sql .= " AND glt.account = ".db_escape($account);

    if ($dimension != 0)
        $sql .= " AND glt.dimension_id = ".($dimension<0?0:db_escape($dimension));

    if ($dimension2 != 0)
        $sql .= " AND glt.dimension2_id = ".($dimension2<0?0:db_escape($dimension2));

    $sql .= " ORDER BY glt.tran_date, glt.counter";

    return db_query($sql, "The transactions for could not be retrieved");
}

function print_GL_transactions()
{
	global $path_to_root, $systypes_array;

	$dim = get_company_pref('use_dimension');
	$dimension = $dimension2 = 0;

	$from = $_POST['PARAM_0'];
	$to = $_POST['PARAM_1'];
	$fromacc = $_POST['PARAM_2'];
	$toacc = $_POST['PARAM_3'];
	if ($dim == 2)
	{
		$dimension = $_POST['PARAM_4'];
		$dimension2 = $_POST['PARAM_5'];
        $currency = $_POST['PARAM_6'];
		$comments = $_POST['PARAM_7'];
		$orientation = $_POST['PARAM_8'];
		$destination = $_POST['PARAM_9'];
	}
	else if ($dim == 1)
	{
		$dimension = $_POST['PARAM_4'];
        $currency = $_POST['PARAM_5'];
		$comments = $_POST['PARAM_6'];
		$orientation = $_POST['PARAM_7'];
		$destination = $_POST['PARAM_8'];
	}
	else
	{
        $currency = $_POST['PARAM_4'];
		$comments = $_POST['PARAM_5'];
		$orientation = $_POST['PARAM_6'];
		$destination = $_POST['PARAM_7'];
	}
	if ($destination)
		include_once($path_to_root . "/reporting/includes/excel_report.inc");
	else
		include_once($path_to_root . "/reporting/includes/pdf_report.inc");
	$orientation = ($orientation ? 'L' : 'P');

	$rep = new FrontReport(_('GL Account Transactions'), "GLAccountTransactions", user_pagesize(), 9, $orientation);
	$dec = user_price_dec();

  //$cols = array(0, 80, 100, 150, 210, 280, 340, 400, 450, 510, 570);
	$cols = array(0, 65, 105, 125, 175, 230, 290, 345, 405, 465, 525);
	//------------0--1---2---3----4----5----6----7----8----9----10-------
	//-----------------------dim1-dim2-----------------------------------
	//-----------------------dim1----------------------------------------
	//-------------------------------------------------------------------
	$aligns = array('left', 'left', 'left',	'left',	'left',	'left',	'left',	'right', 'right', 'right');

	if ($dim == 2)
		$headers = array(_('Type'),	_('Ref'), _('#'),	_('Date'), _('Dimension')." 1", _('Dimension')." 2",
			_('Person/Item'), _('Debit'),	_('Credit'), _('Balance'));
	elseif ($dim == 1)
		$headers = array(_('Type'),	_('Ref'), _('#'),	_('Date'), _('Dimension'), "", _('Person/Item'),
			_('Debit'),	_('Credit'), _('Balance'));
	else
		$headers = array(_('Type'),	_('Ref'), _('#'),	_('Date'), "", "", _('Person/Item'),
			_('Debit'),	_('Credit'), _('Balance'));

	if ($dim == 2)
	{
    	$params =   array( 	0 => $comments,
    				    1 => array('text' => _('Period'), 'from' => $from, 'to' => $to),
    				    2 => array('text' => _('Accounts'),'from' => $fromacc,'to' => $toacc),
                    	3 => array('text' => _('Dimension')." 1", 'from' => get_dimension_string($dimension),
                            'to' => ''),
                    	4 => array('text' => _('Dimension')." 2", 'from' => get_dimension_string($dimension2),
                            'to' => ''),
                        5 => array('text' => _('Currency Filter'), 'from' => $currency, 'to' => ''));
    }
    else if ($dim == 1)
    {
    	$params =   array( 	0 => $comments,
    				    1 => array('text' => _('Period'), 'from' => $from, 'to' => $to),
    				    2 => array('text' => _('Accounts'),'from' => $fromacc,'to' => $toacc),
                    	3 => array('text' => _('Dimension'), 'from' => get_dimension_string($dimension),
                            'to' => ''),
                        4 => array('text' => _('Currency Filter'), 'from' => $currency, 'to' => ''));
    }
    else
    {
    	$params =   array( 	0 => $comments,
    				    1 => array('text' => _('Period'), 'from' => $from, 'to' => $to),
    				    2 => array('text' => _('Accounts'),'from' => $fromacc,'to' => $toacc),
                        3 => array('text' => _('Currency Filter'), 'from' => $currency, 'to' => ''));
    }
    if ($orientation == 'L')
    	recalculate_cols($cols);

	$rep->Font();
	$rep->Info($params, $cols, $headers, $aligns);
	$rep->NewPage();

	$accounts = get_gl_accounts($fromacc, $toacc);

	while ($account=db_fetch($accounts))
	{
		if (is_account_balancesheet($account["account_code"]))
			$begin = "";
		else
		{
			$begin = get_fiscalyear_begin_for_date($from);
			if (date1_greater_date2($begin, $from))
				$begin = $from;
			$begin = add_days($begin, -1);
		}
		$prev_balance = get_gl_balance_from_to_with_curr_filter($begin, $from, $account["account_code"], $dimension, $dimension2, $currency);

		$trans = get_gl_transactions_with_curr_filter($from, $to, -1, $account['account_code'], $dimension, $dimension2, $currency);
		$rows = db_num_rows($trans);
		if ($prev_balance == 0.0 && $rows == 0)
			continue;
		$rep->Font('bold');
		$rep->TextCol(0, 4,	$account['account_code'] . " " . $account['account_name'], -2);
		$rep->TextCol(4, 6, _('Opening Balance'));
		if ($prev_balance > 0.0)
			$rep->AmountCol(7, 8, abs($prev_balance), $dec);
		else
			$rep->AmountCol(8, 9, abs($prev_balance), $dec);
		$rep->Font();
		$total = $prev_balance;
		$rep->NewLine(2);
		if ($rows > 0)
		{
			while ($myrow=db_fetch($trans))
			{
			    if ($currency == '')
			        $row_amount = $myrow['amount'];
			    else
			        $row_amount = $myrow['curr_amount'];

				$total += $row_amount;

				$rep->TextCol(0, 1, $systypes_array[$myrow["type"]], -2);
				$reference = get_reference($myrow["type"], $myrow["type_no"]);
				$rep->TextCol(1, 2, $reference);
				$rep->TextCol(2, 3,	$myrow['type_no'], -2);
				$rep->DateCol(3, 4,	$myrow["tran_date"], true);
				if ($dim >= 1)
					$rep->TextCol(4, 5,	get_dimension_string($myrow['dimension_id']));
				if ($dim > 1)
					$rep->TextCol(5, 6,	get_dimension_string($myrow['dimension2_id']));
				$txt = payment_person_name($myrow["person_type_id"],$myrow["person_id"], false);
				$memo = $myrow['memo_'];
				if ($txt != "")
				{
					if ($memo != "")
						$txt = $txt."/".$memo;
				}
				else
					$txt = $memo;
				$rep->TextCol(6, 7,	$txt, -2);
				if ($row_amount > 0.0)
					$rep->AmountCol(7, 8, abs($row_amount), $dec);
				else
					$rep->AmountCol(8, 9, abs($row_amount), $dec);
				$rep->TextCol(9, 10, number_format2($total, $dec));
				$rep->NewLine();
				if ($rep->row < $rep->bottomMargin + $rep->lineHeight)
				{
					$rep->Line($rep->row - 2);
					$rep->NewPage();
				}
			}
			$rep->NewLine();
		}
		$rep->Font('bold');
		$rep->TextCol(4, 6,	_("Ending Balance"));
		if ($total > 0.0)
			$rep->AmountCol(7, 8, abs($total), $dec);
		else
			$rep->AmountCol(8, 9, abs($total), $dec);
		$rep->Font();
		$rep->Line($rep->row - $rep->lineHeight + 4);
		$rep->NewLine(2, 1);
	}
	$rep->End();
}

?>