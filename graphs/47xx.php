<?php

require_once __DIR__ . '/../Auth.php';

$db = Auth::new_db();

$query = "SELECT code, name FROM accounts WHERE code BETWEEN 4700 AND 4799";
$group_names = $db->read($query, 'code', 'name');

$query = <<<SQL_BLOCK
SELECT
   accounts.code AS code_group,
   SUM(value_num/value_denom) AS v
FROM splits
   INNER JOIN transactions ON (transactions.guid = splits.tx_guid)
   INNER JOIN accounts ON (accounts.guid = splits.account_guid)
WHERE accounts.code BETWEEN 4700 AND 4799
GROUP BY 1
SQL_BLOCK;
$group_values = $db->read($query, 'code_group', 'v');

$trs = [];
$pie = [];
foreach ($group_values as $group => $value) {
    $value_text = number_format($value, 2, '.', ' ') . ' kr';
    $data = ['short' => $group, 'long' => $group_names[$group] ?? 'Group ' . $group, 'value_text' => $value_text];
    $trs[] = implode("</td><td>", array_map('htmlentities', $data));
    $data['value'] = $value;
    $pie[] = $data;
}
$trs = "<tr><td>" . implode("</td></tr>\n\t\t\t\t<tr><td>", $trs) . "</td></tr>";
$json_data = json_encode($pie);
echo <<<HTML_BLOCK
<html>
	<head>
		<title>Cost ditrubition 47xx</title>
		<script src="https://d3js.org/d3.v4.js"></script>
		<script>
			var dataset =; {$json_data};
			
			window.addEventListener('DOMContentLoaded', function() {
	
				var svg = d3.select("#pie svg");
	         var width = +svg.attr("width");
	         var height = +svg.attr("height");
	         var radius = Math.min(width, height) / 2;
	         var g = svg.append("g")
	            .attr("transform", "translate(" + width / 2 + "," + height / 2 + ")");
	
				var color = d3.scaleOrdinal(d3.schemeCategory20b);
	
				var pie = d3.pie()
	            .sort(null)
	            .value(function(d) { return d.value; });
	
				var arc = d3.arc()
					.outerRadius(radius - 10)
					.innerRadius(0);
	
				var path = g.selectAll('path')
					.data(pie(dataset))
					.enter()
					.append('path')
					.attr('d', arc)
					.attr('fill', function(d, i) {
						return color(d.data.long);
					});
				
				path.on('mouseover', function(d) {
					var total = d3.sum(dataset.map(function(d) {
						return d.value;
					}));
					var tooltip = d3.select("#pie .tooltip");
					var percent = Math.round(1000 * d.data.value / total) / 10;
					tooltip.select('.label').html(d.data.long);
					tooltip.select('.value').html(d.data.value_text);
					tooltip.select('.percent').html(percent + '%');
					tooltip.style('display', 'block');					
				});
				path.on('mouseout', function(d) {
					var tooltip = d3.select("#pie .tooltip");
					tooltip.style('display', 'none');
				});
				
				
				
	/*
				var label = d3.arc()
					.outerRadius(radius - 40)
					.innerRadius(radius - 40);
	
				var arc = g.selectAll(".arc")
					.data(pie(data))
					.enter()
					.append("g")
					.attr("class", "arc");
				arc.append("path")
					.attr("d", path)
					.attr("fill", function(d) { return color(d.short); });

				arc.append("text")
					.attr("transform", function(d) { return "translate(" + label.centroid(d) + ")"; })
					.attr("dy", "0.35em")
					.text(function(d) { return d.long; });
	*/
			});
		</script>
		<style>
			.tooltip {
				background: #eee;
				box-shadow: 0 0 5px #999999;
				color: #333;
				display: none;
				font-size: 12px;
				left: 130px;
				padding: 10px;
				position: absolute;
				text-align: center;
				top: 95px;
				width: 80px;
				z-index: 10;
			}
		</style>
	</head>
	<body>
		<h1>Cost ditrubition 47xx</h1>
		<div id="pie" style="position: relative;">
			<svg width="960" height="500" ></svg>
			<div class="tooltip">
				<p><b class="label"></b></p>
				<p><span class="value"></span><br />(<span class="percent"></span>)</p>
			</div>
		</div>
		<table>
			<thead>
				<tr>
					<th>Group</th>
					<th>Group Name</th>
					<th>Sum</th>
				</tr>
			</thead>
			<tbody>
				{$trs}
			</tbody>
		</table>
	</body>
</html>
HTML_BLOCK;


