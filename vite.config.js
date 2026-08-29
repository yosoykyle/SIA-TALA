import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: ["resources/css/app.css", "resources/js/app.js", "resources/css/filament/tala/theme.css"],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        host: "127.0.0.1",
        port: 5273,
        watch: {
            ignored: ["**/storage/framework/views/**"],
        },
    },
});
