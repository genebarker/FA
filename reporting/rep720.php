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
$page_security = 'SA_GLANALYTIC';
// ----------------------------------------------------------------
// $ Revision:	1.0 $
// Creator:	Eugene F. Barker, based on rep706.php by Joe Hunt and
//          and Chaitanya
// date_:	2016-07-29
// Title:	Cash Flow Statement
// ----------------------------------------------------------------
$path_to_root=".."; // used by the includes

include_once("../includes/session.inc");
include_once("../includes/date_functions.inc");
include_once("../includes/data_checks.inc");
include_once("../gl/includes/gl_db.inc");
include_once("../admin/db/tags_db.inc");

//----------------------------------------------------------------------------------------------------

// Define report source data
global $FLOWS_ARR, $CASH_ARR;

$FLOWS_ARR = [
    [
        'title'	=> 'CASH FLOWS FROM / (TO) OPERATING ACTIVITIES',
        'lines'	=> [
            [
                'description'	=> 'Fees and license revenues',
                'accounts'		=> ['4010', '4020', '4050', '4030', '4420', '1200'],
                'factor'		=> -1,
            ],
            [
                'description'	=> 'Cash paid to employees',
                'accounts'		=> ['2310', '2320', '2330', '2340', '2350', '2360', '2370', '2380', '5440', '5450'],
                'factor'		=> -1,
            ],
            [
                'description'	=> 'Cash paid for rent and maintenance',
                'accounts'		=> ['5615', '5685'],
                'factor'		=> -1,
            ],
            [
                'description'	=> 'Cash paid for promotional and advertising expenses',
                'accounts'		=> ['5620', '5745'],
                'factor'		=> -1,
            ],
            [
                'description'	=> 'Cash paid for seminars and conferences',
                'accounts'		=> ['5750'],
                'factor'		=> -1,
            ],
            [
                'description'	=> 'Cash paid for memberships',
                'accounts'		=> ['5760'],
                'factor'		=> -1,
            ],
            [
                'description'	=> 'Cash paid for motor vehicle expenses',
                'accounts'		=> ['5645'],
                'factor'		=> -1,
            ],
            [
                'description'	=> 'Cash paid for entertainment',
                'accounts'		=> ['5675'],
                'factor'		=> -1,
            ],
            [
                'description'	=> 'Cash paid for staff benefits',
                'accounts'		=> ['5680'],
                'factor'		=> -1,
            ],
            [
                'description'	=> 'Cash paid for subscriptions',
                'accounts'		=> ['5690'],
                'factor'		=> -1,
            ],
            [
                'description'	=> 'Cash paid for training costs',
                'accounts'		=> ['5710'],
                'factor'		=> -1,
            ],
            [
                'description'	=> 'Cash paid for other services',
                'accounts'		=> ['5720', '4450', '5715', '5610', '5625', '5635', '5640', '5630', '5665', '5670',
                    '5695', '5700', '5705', '5725', '5730', '5755', '5735', '5740', '5770', '5775',
                    '5780', '1950', '2260', '2100'],
                'factor'		=> -1,
            ],
            [
                'description'	=> 'MFA fees',
                'accounts'		=> ['2200'],
                'factor'		=> -1,
            ],
            [
                'description'	=> 'Advanced deposits',
                'accounts'		=> ['2160'],
                'factor'		=> -1,
            ],
        ],
        'summary'	=> 'Net cash flow from operating activities',
    ],
    [
        'title'	=> 'CASH FLOWS FROM / (TO) INVESTING ACTIVITIES',
        'lines'	=> [
            [
                'description'	=> 'Interest received',
                'accounts'		=> ['4430', '4510', '4435', '4460', '4445'],
                'factor'		=> 1,
            ],
            [
                'description'	=> 'Foreign exchange gain',
                'accounts'		=> ['4450'],
                'factor'		=> 1,
            ],
            [
                'description'	=> 'Acquisition of fixed assets',
                'accounts'		=> ['1820', '1840', '1830', '1850', '1870', '1860'],
                'factor'		=> -1,
            ],
        ],
        'summary'	=> 'Net cash flow from investing activities',
    ],
    [
        'title'	=> 'CASH FLOWS (TO) FINANCING ACTIVITIES',
        'lines'	=> [
            [
                'description'	=> 'Payments made to the Government',
                'accounts'		=> ['3350'],
                'factor'		=> 1,
            ],
        ],
        'summary'	=> 'Net cash flow to financing activities',
    ],
];

$CASH_ARR = [
	[
		'title'	=> 'CASH AND CASH EQUIVALENTS',
		'lines'	=> [
			[
				'description'	=> 'Petty cash',
				'accounts'		=> ['1065'],
				'factor'		=> 1,
			],
			[
				'description'	=> 'Current account',
				'accounts'		=> ['1060'],
				'factor'		=> 1,
			],
			[
				'description'	=> 'USD account',
				'accounts'		=> ['1062'],
				'factor'		=> 1,
			],
			[
				'description'	=> 'NBS interest account',
				'accounts'		=> ['1064'],
				'factor'		=> 1,
			],
			[
				'description'	=> 'Fixed deposits',
				'accounts'		=> ['1070'],
				'factor'		=> 1,
			],
			[
				'description'	=> 'USD trust account',
				'accounts'		=> ['1100'],
				'factor'		=> 1,
			],
			[
				'description'	=> 'Credit card account',
				'accounts'		=> ['1080'],
				'factor'		=> 1,
			],
			[
				'description'	=> 'Local investment',
				'accounts'		=> ['1900'],
				'factor'		=> 1,
			],
		],
		'summary'	=> 'Total cash and cash equivalents',
	],
];

function display_set ($set, $from, $to, FrontReport &$rep, &$pg)
{
    // calculate set totals
    $set_open_balance_total = 0;
    $set_period_balance_total = 0;
    $totals_arr = array();
    foreach ($set as $cat) {
        // print category name
        $rep->Font('bold');
        $rep->TextCol(0, 4, $cat['title']);
        $rep->Font();
        $rep->row -= 4;
        $rep->Line($rep->row);

        // calculate category totals
        $cat_open_balance_total = 0;
        $cat_period_balance_total = 0;
        foreach ($cat['lines'] as $line) {
            // print line description
            $rep->NewLine();
            $rep->TextCol(0, 1,	$line['description']);

            // calculate line totals
            $line_open_balance_total = 0;
            $line_period_balance_total = 0;
            foreach ($line['accounts'] as $account) {
                $open_balance = get_gl_balance_from_to("", $from, $account);
                $period_balance = get_gl_trans_from_to($from, $to, $account);
                if (!$open_balance && !$period_balance)
                    continue;
                $line_open_balance_total += $open_balance * $line['factor'];
                $line_period_balance_total += $period_balance * $line['factor'];
            }

            // print line totals
            $rep->AmountCol(1, 2, $line_open_balance_total);
            $rep->AmountCol(2, 3, $line_period_balance_total);
            $rep->AmountCol(3, 4, $line_open_balance_total + $line_period_balance_total);

            // add line totals to category's
            $cat_open_balance_total += $line_open_balance_total;
            $cat_period_balance_total += $line_period_balance_total;
        }

        // print category totals
        $rep->row -= 4;
        $rep->Line($rep->row);
        $rep->NewLine();
        $rep->TextCol(0, 1,	$cat['summary']);
        $rep->AmountCol(1, 2, $cat_open_balance_total);
        $rep->AmountCol(2, 3, $cat_period_balance_total);
        $rep->AmountCol(3, 4, $cat_open_balance_total + $cat_period_balance_total);
        $rep->row -= 4;
        $rep->Line($rep->row);
        $rep->NewLine(2);

        // add cat totals to set's
        $set_open_balance_total += $cat_open_balance_total;
        $set_period_balance_total += $cat_period_balance_total;

    }

    // return set totals
    $totals_arr[0] = $set_open_balance_total;
    $totals_arr[1] = $set_period_balance_total;
    return $totals_arr;
}


print_cash_flow_statement();

//----------------------------------------------------------------------------------------------------

function print_cash_flow_statement()
{
	global $path_to_root, $FLOWS_ARR, $CASH_ARR;

	// parse input params
	$from = $_POST['PARAM_0'];
	$to = $_POST['PARAM_1'];
	$comments = $_POST['PARAM_2'];
	$orientation = $_POST['PARAM_3'];
	$destination = $_POST['PARAM_4'];

	if ($destination)
		include_once("../reporting/includes/excel_report.inc");
	else
		include_once("../reporting/includes/pdf_report.inc");
	$orientation = ($orientation ? 'L' : 'P');
	$dec = 0;

	// get system account list
	// TODO: use the $coa_list
	$coa_list = get_gl_accounts(null, null, null);

	// define report layout
	$cols = array(0, 200, 350, 425,	500);
	//------------0--1----2----3----4--

	$headers = array( _('Description'), _('Open Balance'), _('Period'), _('Close Balance'));

	$aligns = array('left',	'right', 'right', 'right');

   	$params =   array( 	0 => $comments,
    				    1 => array('text' => _('Period'),'from' => $from, 'to' => $to));

	$rep = new FrontReport(_('Cash Flow Statement'), "CashFlowStatement", user_pagesize(), 9, $orientation);
    if ($orientation == 'L')
    	recalculate_cols($cols);
	$rep->Font();
	$rep->Info($params, $cols, $headers, $aligns);
	$rep->NewPage();

	$calc_open = $calc_period = 0.0;
	$equity_open = $equity_period = 0.0;
	$liability_open = $liability_period = 0.0;
	$econvert = $lconvert = 0;

    // calculate and display cash flows
    $totals_arr = display_set($FLOWS_ARR, $from, $to, $rep, $pg);

    // display flows totals
    $rep->Font('bold');
    $rep->TextCol(0, 4, 'NET INCREASE / (DECREASE) IN CASH');
    $rep->AmountCol(1, 2, $totals_arr[0]);
    $rep->AmountCol(2, 3, $totals_arr[1]);
    $rep->AmountCol(3, 4, $totals_arr[0] + $totals_arr[1]);
    $rep->NewLine(2);

    // calculate and display cash
    display_set($CASH_ARR, $from, $to, $rep, $pg);

    $rep->End();
}