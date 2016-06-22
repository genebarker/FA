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

function add_audit_trail($trans_type, $trans_no, $trans_date, $descr='')
{
	$sql = "INSERT INTO ".TB_PREF."audit_trail"
		. " (type, trans_no, user, fiscal_year, gl_date, description, gl_seq)
			VALUES(".db_escape($trans_type).", ".db_escape($trans_no).","
			. $_SESSION["wa_current_user"]->user. ","
			. get_company_pref('f_year') .","
			. "'". date2sql($trans_date) ."',"
			. db_escape($descr). ", 0)";

	db_query($sql, "Cannot add audit info");
	
	// all audit records beside latest one should have gl_seq set to NULL
	// to avoid need for subqueries (not existing in MySQL 3) all over the code
	$sql = "UPDATE ".TB_PREF."audit_trail SET gl_seq = NULL"
		. " WHERE type=".db_escape($trans_type)." AND trans_no="
		.db_escape($trans_no)." AND id!=".db_insert_id();

	db_query($sql, "Cannot update audit gl_seq");
}

function get_audit_trail_all($trans_type, $trans_no)
{
	$sql = "SELECT * FROM ".TB_PREF."audit_trail"
		." WHERE type=".db_escape($trans_type)." AND trans_no="
		.db_escape($trans_no);

	return db_query($sql, "Cannot get all audit info for transaction");
}

function get_audit_trail_last($trans_type, $trans_no)
{
	$sql = "SELECT * FROM ".TB_PREF."audit_trail"
		." WHERE type=".db_escape($trans_type).
			" AND trans_no=".db_escape($trans_no)." AND NOT ISNULL(gl_seq)";

	$res = db_query($sql, "Cannot get last audit info for transaction");
	if ($res)
		$row = db_fetch($res);

	return $row;
}

/*
	Confirm and close for edition all transactions up to date $todate, 
	and reindex	journal.
*/
// replace close transactions function with version from FA 2.4
function close_transactions($todate) {

	begin_transaction();

	$errors = 0;
	// select only those audit trail records which produce any GL postings
	$sql = "SELECT a.id, gl.tran_date, a.fiscal_year, a.gl_seq,
		 gl.tran_date <= '". date2sql($todate) ."' as closed"
		." FROM ".TB_PREF."gl_trans gl"
		." LEFT JOIN ". TB_PREF."audit_trail a ON 
			(gl.type=a.type AND gl.type_no=a.trans_no)"
		. " WHERE NOT ISNULL(a.gl_seq) AND gl.amount!=0"	// skip old audit records and voided transactions
		. " GROUP BY  a.id, gl.tran_date, a.fiscal_year, a.gl_seq ORDER BY a.fiscal_year, gl.tran_date, a.id";

	$result = db_query($sql, "Cannot select transactions for closing");

	if (db_num_rows($result)) {
		$last_year = 0;

		while ($row = db_fetch($result)) {
			if ($row['fiscal_year'] == null) {
				$errors = 1; continue;
			}
			if ($last_year != $row['fiscal_year']) {
				$last_year = $row['fiscal_year'];
				$counter = 0; // reset counter on fiscal year change
			}

			$seq = $row['closed'] ? ++$counter : 0;
			if ($row['gl_seq'] != $seq)	{ // update transaction status only when gl_seq has changed
				$sql2 = "UPDATE ".TB_PREF."audit_trail SET"
					. " gl_seq=$seq"
					. " WHERE id=".$row['id'];
				db_query($sql2, "Cannot reindex journal");
			}
		}
	}

	if ($errors)
		display_warning(_("Some transactions journal GL postings were not indexed due to lack of audit trail record."));
	else
		update_company_prefs(array('gl_closing_date'=> date2sql($todate)));
	commit_transaction();

	return $errors;
}

/*
	Reopen all transactions for edition up from date $fromdate
*/
function open_transactions($fromdate) {

	$sql = "SELECT a.id, a.gl_date, a.fiscal_year"
		." FROM ".TB_PREF."gl_trans gl"
		." LEFT JOIN ". TB_PREF."audit_trail a ON 
			(gl.type=a.type AND gl.type_no=a.trans_no)"
		. " WHERE gl_date>='". date2sql($fromdate) ."'"
		. " AND !ISNULL(gl_seq)"
		. " ORDER BY a.fiscal_year, a.gl_date, a.id";

	$result = db_query($sql, "Cannot select transactions for openning");

	if (db_num_rows($result)) {
		$last_year = 0;

		while ($row = db_fetch($result)) {
			if ($row['fiscal_year'] == null) {
				continue;
			}
			$sql2 = "UPDATE ".TB_PREF."audit_trail SET"
				. " gl_seq=0"
				. " WHERE id=".$row['id'];
											
			db_query($sql2, "Cannot clear journal order");
		}
	}
}
/*
	Closed transactions have gl_seq number assigned.
*/
// replace is closed trans function with version from FA 2.4
// (includes new dependency get_journal_number)
function get_journal_number($type, $trans_no) {

	$cdate = get_company_pref('gl_closing_date');
	if (!$cdate)
		return false;

// FIXME: gl_date can be badly entered for some transactions due to bug in previous FA versions
	$sql = "SELECT	MAX(gl_seq) as gl_seq  FROM ".TB_PREF."audit_trail"
		. " WHERE type=".db_escape($type)
		." AND trans_no=".db_escape($trans_no)
		." AND gl_date<='$cdate'"; // date is stored in sql format
//		." AND (gl_date<='$cdate'" // some transaction can be not sequenced due to 0 amount, however after edition this could change
//		." OR gl_seq>0)";
	$res = db_query($sql, "Cannot check transaction");
	if (db_num_rows($res))
	{
		$myrow =db_fetch($res);
		return $myrow['gl_seq'] ? $myrow['gl_seq'] : _('None');
	}
	return "-";
}

/*
	Closed transactions have gl_seq number assigned.
*/
function is_closed_trans($type, $trans_no) {

	return get_journal_number($type, $trans_no) > 0;
}

?>
