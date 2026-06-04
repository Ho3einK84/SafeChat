<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>نصب SafeChat v{{ $version }}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Tahoma,sans-serif;background:linear-gradient(145deg,#003d2c,#006a4e);display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.box{background:#fff;border-radius:16px;padding:32px;max-width:560px;width:100%;box-shadow:0 20px 60px rgba(0,20,14,.35)}
h1{color:#003d2c;font-size:22px;margin-bottom:6px}
h1 small{font-size:13px;color:#9ab4ac;font-weight:400}
.sub{color:#6b7b75;font-size:13px;margin-bottom:20px;line-height:1.7}
.success{background:#edfaf4;border:1px solid #00a676;color:#003d2c;border-radius:10px;padding:14px 18px;margin-bottom:14px;font-size:14px;font-weight:600}
.error{background:#fff1f1;border:1px solid #ffb8b8;color:#7a1515;border-radius:10px;padding:14px 18px;margin-bottom:14px;font-size:14px}
pre{background:#1a2e28;color:#d4f0e8;padding:12px;border-radius:8px;font-size:12px;overflow-x:auto;margin:12px 0;direction:ltr;text-align:left}
code{background:#f5f7f6;padding:2px 6px;border-radius:4px;font-size:13px}
.btn{display:inline-flex;align-items:center;gap:8px;background:#00a676;color:#fff;text-decoration:none;border:none;border-radius:10px;padding:12px 28px;font-size:14px;font-weight:700;cursor:pointer;transition:background .2s}
.btn:hover{background:#006a4e}
hr{border:none;border-top:1px solid #d0e6de;margin:16px 0}
.grid{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.badge{background:#edfaf4;border:1px solid #d4f0e8;color:#006a4e;border-radius:99px;padding:4px 12px;font-size:12px;font-weight:600}
.warning{background:#fff7ed;border:1px solid #fde68a;color:#92400e;border-radius:10px;padding:12px 16px;font-size:13px;margin-bottom:14px}
</style>
</head>
<body>
<div class="box">
    <h1>🔒 SafeChat <small>v{{ $version }}</small></h1>
    <p class="sub">راه‌اندازی Laravel — migrations و پیکربندی .env</p>
    <hr>

    @if(empty($runResult))
        @if(count($requirements))
            <div class="error">
                ❌ خطاهای زیر یافت شد:
                <pre>{{ implode("\n", $requirements) }}</pre>
            </div>
        @else
            <div class="success">✅ الزامات PHP برآورده شده است.</div>
            <div class="warning">⚠️ فایل <code>.env</code> را از <code>.env.example</code> کپی کرده و <code>ENCRYPTION_KEY</code> و دیتابیس را تنظیم کنید.</div>
        @endif

        <form method="post" action="{{ url('/install') }}">
            @csrf
            <button type="submit" class="btn">🚀 اجرای migrations</button>
        </form>
    @else
        @if($runResult['success'])
            @foreach($runResult['messages'] as $msg)
                <div class="success">{{ $msg }}</div>
            @endforeach
            <div class="success" style="background:#00a676;color:#fff;border:none;font-size:16px">
                🎉 نصب با موفقیت انجام شد!
            </div>
            <hr>
            <a href="{{ url('/chat') }}" class="btn">🚀 شروع چت</a>
        @else
            <div class="error">
                ❌ خطا در نصب:
                <pre>{{ $runResult['errorMsg'] }}</pre>
            </div>
            <form method="post" action="{{ url('/install') }}">
                @csrf
                <button type="submit" class="btn">🔄 تلاش مجدد</button>
            </form>
        @endif
    @endif
</div>
</body>
</html>
