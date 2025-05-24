'use client'

import type React from "react"
import { Inter } from "next/font/google"
import { ThemeProvider } from "@/components/theme-provider"
import { AuthProvider } from "@/contexts/auth-context"
import { Toaster } from "@/components/ui/toaster"
import { MainLayout } from "@/components/layout"
import { usePathname } from "next/navigation"
import { useEffect, useState } from "react"
import { QueryClient, QueryClientProvider } from "@tanstack/react-query"
import { UserGuide } from "@/components/user-guide"
// Ensure globals.css is imported if it was in the original layout and AppClientLayout is the new root for styles applied via globals.css
// However, globals.css is usually imported in the main layout.tsx or page.tsx.
// For this refactor, we'll assume globals.css is handled by the main layout.tsx.

const inter = Inter({ subsets: ["latin"] })

export function AppClientLayout({
  children,
}: Readonly<{
  children: React.ReactNode
}>) {
  const [token, setToken] = useState<string | undefined>()
  const pathname = usePathname() || ""
  
  const noLayoutRoutes = ['/login', '/login/forgot-password', '/reset-password']
  const isNoLayoutPage = noLayoutRoutes.includes(pathname)
  
  const [queryClient] = useState(() => new QueryClient())

  useEffect(() => {
    const cookieToken = document.cookie
      .split('; ')
      .find(row => row.startsWith('token='))
      ?.split('=')[1]
    setToken(cookieToken)
  }, [])

  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider 
        attribute="class" 
        defaultTheme="system" 
        enableSystem 
        disableTransitionOnChange
      >
        <AuthProvider>
          {!isNoLayoutPage && token ? (
            <MainLayout>
              {children}
              <UserGuide />
            </MainLayout>
          ) : (
            <>{children}</>
          )}
          <Toaster />
        </AuthProvider>
      </ThemeProvider>
    </QueryClientProvider>
  )
} 