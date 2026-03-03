window.onload = function() {
  //<editor-fold desc="Changeable Configuration Block">

  // the following lines will be replaced by docker/configurator, when it runs in a docker-container
  // url: "https://petstore.swagger.io/v2/swagger.json",
  
  window.ui = SwaggerUIBundle({
    url: "http://localhost/github/sizzle_rhythm_backend/swagger.json",
    dom_id: '#swagger-ui',
    deepLinking: true,
    docExpansion: 'none', // Collapse all sections by default
    presets: [
      SwaggerUIBundle.presets.apis,
      SwaggerUIStandalonePreset
    ],
    plugins: [
      SwaggerUIBundle.plugins.DownloadUrl
    ],
    layout: "StandaloneLayout"
  });

  //</editor-fold>
};
