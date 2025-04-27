document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('fnord-form');
    const resultsDiv = document.getElementById('fnord-results');

    if (!form || !resultsDiv) {
        console.error('FNORD: Form or results container not found');
        return;
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const urlInput = document.getElementById('fnord-url');
        const rumorInput = document.getElementById('fnord-rumor');
        
        if (!urlInput || !rumorInput) {
            console.error('FNORD: Form inputs not found');
            alert('Form configuration error. Please contact support.');
            return;
        }

        const url = urlInput.value;
        const rumor = rumorInput.value;
        
        if (!url && !rumor) {
            alert('Please fill out at least one field.');
            return;
        }
        
        resultsDiv.style.display = 'none';
        resultsDiv.innerHTML = 'Analyzing...';
        
        try {
            jQuery.ajax({
                url: fnordAjax.ajax_url,
                method: 'POST',
                data: {
                    action: 'fnord_submit',
                    nonce: fnordAjax.nonce,
                    url: url,
                    rumor: rumor
                },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        let html = '<h2>Analysis Results</h2>';
                        html += '<p><strong>Category:</strong> ' + (data.analysis.category || 'N/A') + '</p>';
                        html += '<p><strong>Reasoning:</strong> ' + (data.analysis.reasoning || 'No reasoning provided') + '</p>';
                        
                        // Pie chart
                        html += '<h3>Breakdown</h3>';
                        html += '<canvas id="fnord-pie-chart" style="max-width: 300px;"></canvas>';
                        
                        // Hardcoded articles
                        html += '<h3>Supporting Articles</h3><ul>';
                        if (data.articles && data.articles.length > 0) {
                            data.articles.forEach(function(article) {
                                html += '<li><a href="' + article.url + '">' + article.title + '</a> - ' + 
                                        article.publication + ' - ' + article.date + ' - ' + article.author + '</li>';
                            });
                        } else {
                            html += '<li>No supporting articles found.</li>';
                        }
                        html += '</ul>';
                        
                        // Supplementary articles from Grok 3
                        html += '<h3>Additional Sources</h3><ul>';
                        if (data.supplementary_articles && data.supplementary_articles.length > 0) {
                            data.supplementary_articles.forEach(function(article) {
                                html += '<li><a href="' + article.url + '">' + article.title + '</a> - ' + 
                                        article.publication + ' - ' + article.date + ' - ' + article.author + '</li>';
                            });
                        } else {
                            html += '<li>No additional sources found.</li>';
                        }
                        html += '</ul>';
                        
                        // Final verdict
                        html += '<h3>Final Verdict</h3>';
                        html += '<p><strong>Truthfulness:</strong> ' + (data.pie_data.truthful || 0) + '%</p>';
                        html += '<p><strong>Misinformation:</strong> ' + (data.pie_data.misinformation || 0) + '%</p>';
                        html += '<p><strong>Bias:</strong> ' + (data.pie_data.bias || 0) + '%</p>';
                        
                        resultsDiv.innerHTML = html;
                        resultsDiv.style.display = 'block';
                        
                        // Render pie chart
                        try {
                            const ctx = document.getElementById('fnord-pie-chart').getContext('2d');
                            new Chart(ctx, {
                                type: 'pie',
                                data: {
                                    labels: ['Truthful', 'Misinformation', 'Bias'],
                                    datasets: [{
                                        data: [
                                            data.pie_data.truthful || 0,
                                            data.pie_data.misinformation || 0,
                                            data.pie_data.bias || 0
                                        ],
                                        backgroundColor: ['#36A2EB', '#FF6384', '#FFCE56']
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    plugins: {
                                        legend: { position: 'top' },
                                        title: { display: true, text: 'Analysis Breakdown' }
                                    }
                                }
                            });
                        } catch (chartError) {
                            console.error('FNORD: Pie chart rendering failed:', chartError);
                            resultsDiv.innerHTML += '<p>Error rendering pie chart. Please try again.</p>';
                        }
                    } else {
                        console.error('FNORD: AJAX response error:', response.data.message);
                        resultsDiv.innerHTML = '<p>Error: ' + (response.data.message || 'Unknown error') + '</p>';
                        resultsDiv.style.display = 'block';
                    }
                },
                error: function(xhr, status, error) {
                    console.error('FNORD: AJAX request failed:', status, error, xhr.responseText);
                    resultsDiv.innerHTML = '<p>AJAX Error: ' + error + '. Please check the console for details.</p>';
                    resultsDiv.style.display = 'block';
                }
            });
        } catch (ajaxError) {
            console.error('FNORD: AJAX setup failed:', ajaxError);
            resultsDiv.innerHTML = '<p>Error initiating analysis. Please check the console for details.</p>';
            resultsDiv.style.display = 'block';
        }
    });
});