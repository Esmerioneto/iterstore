<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>GraphiQL · Admin</title>
    <!-- GraphiQL 3.9.0 + React 18.3.1, kept in the project like SweetAlert2 (no CDN) -->
    <link rel="stylesheet" href="/assets/vendor/graphiql/graphiql.min.css">
    <style>
        body { margin: 0; height: 100vh; display: flex; flex-direction: column; font-family: sans-serif; }
        nav { padding: 6px 12px; font-size: 14px; }
        #graphiql { flex: 1; }
    </style>
</head>
<body>
    <nav><a href="/admin/index">&larr; Voltar ao admin</a></nav>
    <div id="graphiql">Carregando GraphiQL…</div>
    <script src="/assets/vendor/graphiql/react.production.min.js"></script>
    <script src="/assets/vendor/graphiql/react-dom.production.min.js"></script>
    <script src="/assets/vendor/graphiql/graphiql.min.js"></script>
    <script>
        ReactDOM.createRoot(document.getElementById('graphiql')).render(
            React.createElement(GraphiQL, { fetcher: GraphiQL.createFetcher({ url: '/core/graphql/index' }) })
        );
    </script>
</body>
</html>
