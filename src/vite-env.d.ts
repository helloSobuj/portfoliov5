/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_SUPABASE_URL?: string
  readonly VITE_SUPABASE_ANON_KEY?: string
  readonly VITE_CONTACT_MAP_EMBED_URL?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
