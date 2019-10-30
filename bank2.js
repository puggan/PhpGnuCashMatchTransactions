bi.render_account_list = function(selected_code) {
	let html = '<option value="">-- Select Account --</option>';
	for(let account_code in bi.accounts)
	{
		if(account_code == selected_code)
		{
			html += '<option value="' + account_code + '" selected>' + bi.accounts[account_code] + '</option>';
		}
		else
		{
			html += '<option value="' + account_code + '">' + bi.accounts[account_code] + '</option>';
		}
	}
	return html;
};

bi.render = function(row) {
	let html = '<form method="post" action="bank2.php?account=' + bi.selected_account + '&amp;ajax_failed" onsubmit="bi.ajax_add_row(' + row.bank_t_row + '); return false;">';
	if(row.amount < 0)
	{
		html += '<fieldset class="dec">';
	}
	else
	{
		html += '<fieldset class="inc">';
	}
	html += '<legend>' + row.amount + ' kr @ ' + row.bdate + '</legend>';
	html += '<label><span>Date:</span><input type="date" name="date" class="input_date" value="' + row.bdate + '" /></label>';
	html += '<label><span>Amount:</span><input type="number" class="input_amount" name="amount" value="' + Math.abs(row.amount) + '" /></label>';
	html += '<label><span>From:</span><select name="from" class="input_from">';
	html += bi.render_account_list(row.amount > 0 ? false : bi.selected_account);
	html += '</select></label>';
	html += '<label><span>To:</span><select name="to" class="input_to">';
	html += bi.render_account_list(row.amount > 0 ? bi.selected_account : false);
	html += '</select></label>';
	html += '<br />';
	html += '<label><span>Text:</span><input class="input_text" type="text" name="text" value="' + row.vtext + '" /></label>';
	html += '<label><input class="input_submit" type="submit" value="Add Row" /></label>';

	if(row.matches) {
		if(row.matches.tx && Object.keys(row.matches.tx).length) {
			html += '<h3>Match sugestions (TR)</h3>';
			html += '<ul>';
			for(i in row.matches.tx) {
				var tx = row.matches.tx[i];
				var text = tx.date + ', ' + tx.other_account + ', ' + tx.value + ', ' + tx.description;
				html += '<li onclick="bi.ajax_connect_row(' + row.bank_t_row + ', \'' + i + '\')">' + text + '</li>';
			}
			html += '</ul>';
		}

		if(row.matches.rows && Object.keys(row.matches.rows).length) {
			html += '<h3>Match sugestions (Text)</h3>';
			html += '<ul>';
			for(i in row.matches.rows) {
				m = row.matches.rows[i];
				html += '<li onclick="bi.setAccount(this, \'' + m.code + '\')">' + m.name + ', ' + m.connections + ' connections, ';
				if(m.amount_from < m.amount_to)
					html += 'amount in range ' + m.amount_from + ' - ' + m.amount_to + ', ';
				else
					html += 'amount ' + m.amount_from + ', ';
				if(m.date_from < m.date_to)
					html += 'dates in range ' + m.date_from + ' - ' + m.date_to;
				else
					html += 'date ' + m.date_from;
				html += '</li>';
			}
			html += '</ul>';
		}
	}


	html += '</fieldset>';
	html += '</form>';

	return html;
};

bi.ajax_add_row = function(row) {
	let li = $('li#item-' + row);
	let data = {
		action: 'add',
		row: row
	};
	let formdata = new FormData(li.find('form')[0]);
	data.date = formdata.get('date');
	data.amount = formdata.get('amount');
	data.from = formdata.get('from');
	data.to = formdata.get('to');
	data.text = formdata.get('text');

	if(!data.date) return false;
	if(!data.amount) return false;
	if(!data.from) return false;
	if(!data.to) return false;
	if(!data.text) return false;

	li.css('opacity', 0.5);

	$.ajax(
		{
			type: 'POST',
			url: 'bank_api.php',
			data: data,
			error: function () {
				li.css('opacity', 1);
			},
			success: function() {
				li.remove();

				// TODO: handle returned data
			}
		}
	);

	//let data.date = li.
	console.log(data);
	return false;
};

bi.ajax_connect_row = function(row, guid) {
	let li = $('li#item-' + row);
	let data = {
		action: 'connect',
		row: row,
		guid: guid
	};

	li.css('opacity', 0.5);

	$.ajax(
		{
			type: 'POST',
			url: 'bank_api.php',
			data: data,
			error: function () {
				li.css('opacity', 1);
			},
			success: function() {
				li.remove();

				// TODO: handle returned data
			}
		}
	);

	//let data.date = li.
	console.log(data);
	return false;
};

bi.setAccount = function(source, account) {

	if(!account) return false;
	if(account == bi.selected_account) return false;

	selects = $(source).closest('form').find('select');

	selects.each(function() {
			let s = $(this);
			if(s.val() != bi.selected_account) {
				s.val(account);
				s.trigger("chosen:updated");
			}
		}
	);
};

bi.init = function() {
	for(i in bi.rows)
	{
		let row = bi.rows[i];

		let li = $('<li>')
			.attr('id', 'item-' + row.bank_t_row)
			.html(bi.render(row));
		$('#main_list')
			.append(li);
	}

	$('select')
		.chosen({disable_search_threshold: 2});
	$.datepicker.setDefaults({dateFormat: 'yy-mm-dd'});
	let dateselectors = $('INPUT[type=date]');
	dateselectors.datepicker();
	dateselectors.each(function() {
		$(this)
			.datepicker(
				'setDate',
				$(this)
					.val()
			);
	});
};
