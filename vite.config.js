import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import tailwindcss from "@tailwindcss/vite";
import { readFileSync, readdirSync } from "node:fs";
import { resolve, sep } from "node:path";

const pdfRoot = resolve("node_modules/pdfjs-dist");
const pdfVersion = JSON.parse(readFileSync(resolve(pdfRoot, "package.json"), "utf8")).version;
const pdfAssetFolders = ["cmaps", "standard_fonts", "wasm", "iccs"];

function privatePdfAssets() {
    return {
        name: "tala-private-pdf-assets",
        generateBundle() {
            for (const folder of pdfAssetFolders) {
                for (const filename of readdirSync(resolve(pdfRoot, folder))) {
                    this.emitFile({ type: "asset", fileName: `pdfjs/${pdfVersion}/${folder}/${filename}`,
                        source: readFileSync(resolve(pdfRoot, folder, filename)) });
                }
            }
        },
        configureServer(server) {
            server.middlewares.use(`/pdfjs/${pdfVersion}/`, (request, response, next) => {
                const file = resolve(pdfRoot, `.${request.url.split("?")[0]}`);
                if (!file.startsWith(`${pdfRoot}${sep}`) || !pdfAssetFolders.some((folder) => file.startsWith(`${resolve(pdfRoot, folder)}${sep}`))) {
                    return next();
                }
                try {
                    response.setHeader("Content-Type", file.endsWith(".wasm") ? "application/wasm" : "application/octet-stream");
                    response.end(readFileSync(file));
                } catch { next(); }
            });
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: ["resources/css/app.css", "resources/js/app.js", "resources/css/filament/tala/theme.css", "resources/js/payment-proof-preview.js"],
            refresh: true,
        }),
        tailwindcss(),
        privatePdfAssets(),
    ],
    server: {
        host: "127.0.0.1",
        port: 5273,
        watch: {
            ignored: ["**/storage/framework/views/**"],
        },
    },
});
