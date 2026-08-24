<?php require_once __DIR__ . "/config.php"; ?>
<html>
<head>
  <title>TF Colocalization Heatmap</title>
  <meta http-equiv="content-type" content="text/html; charset=utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <link href="static/lib/morpheus/css/morpheus-latest.min.css" rel="stylesheet">
  <script type="text/javascript" src="static/lib/morpheus/js/morpheus-external-latest.min.js"></script>
  <script type="text/javascript" src="static/lib/morpheus/js/morpheus-latest.min.js"></script>

<script type="text/javascript">
  (function(){
    var oldFocus = HTMLElement.prototype.focus;

    HTMLElement.prototype.focus = function(options){
      try {
        oldFocus.call(this, { preventScroll: true });
      } catch(e) {
        oldFocus.call(this);
      }
    };
  })();
</script>

</head>
<body style="height:100%">
  <noscript>
    <p>Please enable JavaScript</p>
  </noscript>
  <div id="vis"></div>
  <script type="text/javascript">
    window.onerror = function() {
        morpheus.DialogUtil.clear();
        morpheus.FormBuilder.showInModal({
            title: 'Error',
            html: 'Oops, something went wrong. Please try again.',
        });
    };
    var searchString = window.location.search;
    if (searchString.length === 0) {
        searchString = window.location.hash;
    }
    var landingPage = new morpheus.LandingPage();
    landingPage.$el.prependTo($(document.body));
    if (searchString.length === 0) {
        landingPage.show();
    } else {
        searchString = searchString.substring(1);
        var keyValuePairs = searchString.split('&');
        var params = {};
        for (var i = 0; i < keyValuePairs.length; i++) {
            var pair = keyValuePairs[i].split('=');
            params[pair[0]] = decodeURIComponent(pair[1]);
        }
        if (params.json) {
            var options = JSON.parse(decodeURIComponent(params.json));
            landingPage.open(options);
        } else if (params.url) { // url to config
            var $loading = morpheus.Util.createLoadingEl();
            $loading.appendTo($('#vis'));
            morpheus.Util.getText(params.url).then(function(text) {
                var options = JSON.parse(text);
                landingPage.open(options);
                try {
                  if (window.parent && window.parent.document) {
                    var statusEl = window.parent.document.getElementById("div_heatmap_status_message");
                    if (statusEl) {
                      statusEl.textContent = '';
                      statusEl.appendChild(window.parent.document.createTextNode('    '));
                      var link = window.parent.document.createElement('a');
                      link.setAttribute('href', String(window.location.href));
                      link.setAttribute('target', '_blank');
                      var icon = window.parent.document.createElement('i');
                      icon.className = 'fa-solid fa-maximize';
                      link.appendChild(icon);
                      link.appendChild(window.parent.document.createTextNode('  Open workspace in widescreen mode'));
                      statusEl.appendChild(link);
                      statusEl.appendChild(window.parent.document.createTextNode('   You may want to to cluster the heatmap: [Tools] -> [Hierarchical Clustering] -> [Cluster: Rows and columns] -> [OK]'));
                    }
                  }
                } catch (e) {
                  // Ignore cross-origin access errors.
                }
            }).catch(function(err) {
                console.log('Unable to get config file');
                landingPage.show();
            }).finally(function() {
                $loading.remove();
            });

        } else {
            landingPage.show();
        }
    }
  </script>


</body>
</html>
