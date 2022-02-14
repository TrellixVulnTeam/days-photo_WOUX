<!DOCTYPE html>
<html lang="ja">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>days.</title>
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <meta name="title" content="days. - かんたんフォト管理 - ">
    <meta name="author" content="COLORBOX Inc.">
    <meta name="description" content="いつか消えてしまう、あの写真も、ずっと残る。 days.は新しいタイプの “かんたんフォト管理” サービス。30秒でアルバムが完成✅ 無料で印刷・発送✅">
    <link type="text/css" href="./vendor/@fortawesome/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link type="text/css" href="./css/pixel.css" rel="stylesheet">
    <link type="text/css" href="./css/app.css" rel="stylesheet">

</head>

<body>
    <header class="header-global">
        <nav id="navbar-main" aria-label="Primary navigation" class="navbar navbar-main navbar-expand-lg nav-theme-white navbar-light">
            <div class="container position-relative">
                <a class="me-lg-5 display-4 px-2 px-lg-0" href="/">days.</a>
                <div class="navbar-collapse collapse me-auto" id="navbar_global">
                    <div class="navbar-collapse-header">
                        <div class="row">
                            <div class="col-6 collapse-brand"><a href="/">days.</a></div>
                            <div class="col-6 collapse-close"><a href="#navbar_global" class="fas fa-times" data-bs-toggle="collapse" data-bs-target="#navbar_global" aria-controls="navbar_global" aria-expanded="false" title="close" aria-label="Toggle navigation"></a></div>
                        </div>
                    </div>
                    <ul class="navbar-nav navbar-nav-hover align-items-lg-center">
                    </ul>
                </div>
                <div class="d-flex align-items-center">
                </div>
            </div>
        </nav>
    </header>

    <div class="wrapper bg-white">
        @yield('content')
        <div class="push"></div>
    </div>

    <footer class="footer pt-5 pb-6 bg-white text-gray">
        <div class="container">
            <div class="row">
                <div class="col-md-12 text-center">
                    <p>2022 © <a href="https://colorbox.tech">COLORBOX Inc.</a></p>
                </div>
            </div>
        </div>
    </footer>
    <!-- Core -->
    <script src="./vendor/@popperjs/core/dist/umd/popper.min.js"></script>
    <script src="./vendor/bootstrap/dist/js/bootstrap.min.js"></script>
    <script src="./vendor/headroom.js/dist/headroom.min.js"></script>
    <!-- Vendor JS -->
    <script src="./vendor/onscreen/dist/on-screen.umd.min.js"></script>
    <script src="./vendor/jarallax/dist/jarallax.min.js"></script>
    <script src="./vendor/smooth-scroll/dist/smooth-scroll.polyfills.min.js"></script>
    <script src="./vendor/vivus/dist/vivus.min.js"></script>
    <script src="./vendor/vanillajs-datepicker/dist/js/datepicker.min.js"></script>
    <script async defer="defer" src="https://buttons.github.io/buttons.js"></script>
    <!-- Pixel JS -->
    <script src="./assets/js/pixel.js"></script>
    @yield('script')
</body>

</html>
