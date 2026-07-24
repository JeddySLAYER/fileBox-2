import { create } from 'zustand'
import { persist } from 'zustand/middleware'

export const useAuthStore = create(
  persist(
    (set, get) => ({
      token: null,
      user: null,
      mustChangePassword: false,

      setSession: ({ token, user, mustChangePassword }) =>
        set({
          token,
          user,
          mustChangePassword: Boolean(mustChangePassword ?? user?.must_change_password),
        }),

      setUser: (user) =>
        set({
          user,
          mustChangePassword: Boolean(user?.must_change_password),
        }),

      setMustChangePassword: (value) => set({ mustChangePassword: Boolean(value) }),

      clearSession: () => set({ token: null, user: null, mustChangePassword: false }),

      isAuthenticated: () => Boolean(get().token),
    }),
    {
      name: 'filebox-auth',
      partialize: (state) => ({
        token: state.token,
        user: state.user,
        mustChangePassword: state.mustChangePassword,
      }),
    },
  ),
)
