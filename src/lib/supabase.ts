import { createClient } from '@supabase/supabase-js'

const url = import.meta.env.VITE_SUPABASE_URL
const anonKey = import.meta.env.VITE_SUPABASE_ANON_KEY

/**
 * `null` until VITE_SUPABASE_URL / VITE_SUPABASE_ANON_KEY are set (see
 * .env.example). Pages fall back to mock data (src/data/mock.ts) so the
 * app runs before a Supabase project exists.
 */
export const supabase = url && anonKey ? createClient(url, anonKey) : null

export const isSupabaseConfigured = supabase !== null
