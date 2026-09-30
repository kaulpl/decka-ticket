const runtime=await import(process.env.DECKA_PLAYWRIGHT_MODULE||'playwright');
export const chromium=runtime.chromium;
export const launchOptions={headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{})};
