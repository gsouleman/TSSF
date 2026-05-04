$(document).ready(function() {
	// Demo charts only run when canvas elements exist (most HMS pages omit them).
	var barEl = document.getElementById('bargraph');
	if (barEl && typeof barEl.getContext === 'function') {
		var barChartData = {
			labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
			datasets: [{
				label: 'Dataset 1',
				backgroundColor: 'rgba(0, 158, 251, 0.5)',
				borderColor: 'rgba(0, 158, 251, 1)',
				borderWidth: 1,
				data: [35, 59, 80, 81, 56, 55, 40]
			}, {
				label: 'Dataset 2',
				backgroundColor: 'rgba(255, 188, 53, 0.5)',
				borderColor: 'rgba(255, 188, 53, 1)',
				borderWidth: 1,
				data: [28, 48, 40, 19, 86, 27, 90]
			}]
		};

		var ctx = barEl.getContext('2d');
		window.myBar = new Chart(ctx, {
			type: 'bar',
			data: barChartData,
			options: {
				responsive: true,
				legend: {
					display: false,
				}
			}
		});
	}

	var lineEl = document.getElementById('linegraph');
	if (lineEl && typeof lineEl.getContext === 'function') {
		var lineChartData = {
			labels: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
			datasets: [{
				label: "My First dataset",
				backgroundColor: "rgba(0, 158, 251, 0.5)",
				data: [100, 70, 20, 100, 120, 50, 70, 50, 50, 100, 50, 90]
			}, {
			label: "My Second dataset",
			backgroundColor: "rgba(255, 188, 53, 0.5)",
			fill: true,
			data: [28, 48, 40, 19, 86, 27, 20, 90, 50, 20, 90, 20]
			}]
		};

		var linectx = lineEl.getContext('2d');
		window.myLine = new Chart(linectx, {
			type: 'line',
			data: lineChartData,
			options: {
				responsive: true,
				legend: {
					display: false,
				},
				tooltips: {
					mode: 'index',
					intersect: false,
				}
			}
		});
	}
	
	// Bar Chart 2
	
    barChart();
    
    $(window).resize(function(){
        barChart();
    });
    
    function barChart(){
        $('.bar-chart').find('.item-progress').each(function(){
            var itemProgress = $(this),
            itemProgressWidth = $(this).parent().width() * ($(this).data('percent') / 100);
            itemProgress.css('width', itemProgressWidth);
        });
    };
});